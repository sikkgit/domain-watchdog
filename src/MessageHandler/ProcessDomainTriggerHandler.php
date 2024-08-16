<?php

namespace App\MessageHandler;

use App\Config\Connector\ConnectorInterface;
use App\Config\TriggerAction;
use App\Config\WebhookScheme;
use App\Entity\Domain;
use App\Entity\DomainEvent;
use App\Entity\WatchList;
use App\Entity\WatchListTrigger;
use App\Message\ProcessDomainTrigger;
use App\Notifier\DomainOrderErrorNotification;
use App\Notifier\DomainOrderNotification;
use App\Notifier\DomainUpdateNotification;
use App\Repository\DomainRepository;
use App\Repository\WatchListRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Notifier\Recipient\Recipient;
use Symfony\Component\Notifier\Transport\AbstractTransportFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
final readonly class ProcessDomainTriggerHandler
{
    private Address $sender;

    public function __construct(
        string $mailerSenderEmail,
        string $mailerSenderName,
        private WatchListRepository $watchListRepository,
        private DomainRepository $domainRepository,
        private KernelInterface $kernel,
        private LoggerInterface $logger,
        private HttpClientInterface $client,
        private MailerInterface $mailer
    ) {
        $this->sender = new Address($mailerSenderEmail, $mailerSenderName);
    }

    /**
     * @throws TransportExceptionInterface
     * @throws \Exception
     * @throws ExceptionInterface
     */
    public function __invoke(ProcessDomainTrigger $message): void
    {
        /** @var WatchList $watchList */
        $watchList = $this->watchListRepository->findOneBy(['token' => $message->watchListToken]);
        /** @var Domain $domain */
        $domain = $this->domainRepository->findOneBy(['ldhName' => $message->ldhName]);

        $connector = $watchList->getConnector();
        if (null !== $connector && $domain->getDeleted()) {
            $this->logger->notice('Watchlist {watchlist} is linked to connector {connector}. A purchase attempt will be made for domain name {ldhName} with provider {provider}.', [
                'watchlist' => $message->watchListToken,
                'connector' => $connector->getId(),
                'ldhName' => $message->ldhName,
                'provider' => $connector->getProvider()->value,
            ]);
            try {
                $provider = $connector->getProvider();
                if (null === $provider) {
                    throw new \Exception('Provider not found');
                }

                $connectorProviderClass = $provider->getConnectorProvider();

                /** @var ConnectorInterface $connectorProvider */
                $connectorProvider = new $connectorProviderClass($connector->getAuthData(), $this->client);

                $connectorProvider->orderDomain($domain, $this->kernel->isDebug());

                $email = (new DomainOrderNotification($this->sender, $domain, $connector))
                    ->asEmailMessage(new Recipient($watchList->getUser()->getEmail()));
                $this->mailer->send($email->getMessage());
            } catch (\Throwable) {
                $this->logger->warning('Unable to complete purchase. An error message is sent to user {username}.', [
                    'username' => $watchList->getUser()->getUserIdentifier(),
                ]);
                $email = (new DomainOrderErrorNotification($this->sender, $domain))
                    ->asEmailMessage(new Recipient($watchList->getUser()->getEmail()));
                $this->mailer->send($email->getMessage());
            }
        }

        /** @var DomainEvent $event */
        foreach ($domain->getEvents()->filter(fn ($event) => $message->updatedAt < $event->getDate() && $event->getDate() < new \DateTime()) as $event) {
            $watchListTriggers = $watchList->getWatchListTriggers()
                ->filter(fn ($trigger) => $trigger->getEvent() === $event->getAction());

            /** @var WatchListTrigger $watchListTrigger */
            foreach ($watchListTriggers->getIterator() as $watchListTrigger) {
                $this->logger->info('Action {event} has been detected on the domain name {ldhName}. A notification is sent to user {username}.', [
                    'event' => $event->getAction(),
                    'ldhName' => $message->ldhName,
                    'username' => $watchList->getUser()->getUserIdentifier(),
                ]);

                $recipient = new Recipient($watchList->getUser()->getEmail());
                $notification = new DomainUpdateNotification($this->sender, $event);

                if (TriggerAction::SendEmail == $watchListTrigger->getAction()) {
                    $this->mailer->send($notification->asEmailMessage($recipient)->getMessage());
                } elseif (TriggerAction::SendChat == $watchListTrigger->getAction()) {
                    if (null !== $watchList->getWebhookDsn()) {
                        foreach ($watchList->getWebhookDsn() as $dsnString) {
                            $dsn = new \Symfony\Component\Notifier\Transport\Dsn($dsnString);

                            $scheme = $dsn->getScheme();
                            $webhookScheme = WebhookScheme::tryFrom($scheme);
                            if (null !== $webhookScheme) {
                                $transportFactoryClass = $webhookScheme->getChatTransportFactory();
                                /** @var AbstractTransportFactory $transportFactory */
                                $transportFactory = new $transportFactoryClass();
                                $transportFactory->create($dsn)->send($notification->asChatMessage());
                            }
                        }
                    }
                }
            }
        }
    }
}
