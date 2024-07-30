<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\WatchListRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WatchListRepository::class)]
#[ApiResource(
    shortName: 'Watchlist',
    operations: [
        new GetCollection(
            routeName: 'watchlist_get_all_mine',
            normalizationContext: ['groups' => 'watchlist:list'],
            name: 'get_all_mine',
        ),
        new Get(
            normalizationContext: ['groups' => 'watchlist:item']
        ),
        new Post(
            routeName: 'watchlist_create', normalizationContext: ['groups' => 'watchlist:list'],
            denormalizationContext: ['groups' => 'watchlist:create'],
            name: 'create'
        ),
        new Patch(
            normalizationContext: ['groups' => 'watchlist:item'],
            denormalizationContext: ['groups' => 'watchlist:update']
        ),
        new Delete()
    ],
)]
class WatchList
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[Groups(['watchlist:item', 'watchlist:list'])]
    private string $token;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'watchLists')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    /**
     * @var Collection<int, Domain>
     */
    #[ORM\ManyToMany(targetEntity: Domain::class, inversedBy: 'watchLists')]
    #[ORM\JoinTable(name: 'watch_lists_domains',
        joinColumns: [new ORM\JoinColumn(name: 'watch_list_token', referencedColumnName: 'token')],
        inverseJoinColumns: [new ORM\JoinColumn(name: 'domain_ldh_name', referencedColumnName: 'ldh_name')])]
    #[Groups(['watchlist:list', 'watchlist:item', 'watchlist:create', 'watchlist:update'])]
    private Collection $domains;

    /**
     * @var Collection<int, WatchListTrigger>
     */
    #[ORM\OneToMany(targetEntity: WatchListTrigger::class, mappedBy: 'watchList', cascade: ['persist'], orphanRemoval: true)]
    #[Groups(['watchlist:list', 'watchlist:item', 'watchlist:create', 'watchlist:update'])]
    #[SerializedName("triggers")]
    private Collection $watchListTriggers;

    #[ORM\ManyToOne(inversedBy: 'watchLists')]
    #[Groups(['watchlist:list', 'watchlist:item', 'watchlist:create', 'watchlist:update'])]
    private ?Connector $connector = null;


    public function __construct()
    {
        $this->token = Uuid::v4();
        $this->domains = new ArrayCollection();
        $this->watchListTriggers = new ArrayCollection();
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    /**
     * @return Collection<int, Domain>
     */
    public function getDomains(): Collection
    {
        return $this->domains;
    }

    public function addDomain(Domain $domain): static
    {
        if (!$this->domains->contains($domain)) {
            $this->domains->add($domain);
        }

        return $this;
    }

    public function removeDomain(Domain $domain): static
    {
        $this->domains->removeElement($domain);

        return $this;
    }

    /**
     * @return Collection<int, WatchListTrigger>
     */
    public function getWatchListTriggers(): Collection
    {
        return $this->watchListTriggers;
    }

    public function addWatchListTrigger(WatchListTrigger $watchListTrigger): static
    {
        if (!$this->watchListTriggers->contains($watchListTrigger)) {
            $this->watchListTriggers->add($watchListTrigger);
            $watchListTrigger->setWatchList($this);
        }

        return $this;
    }

    public function removeWatchListTrigger(WatchListTrigger $watchListTrigger): static
    {
        if ($this->watchListTriggers->removeElement($watchListTrigger)) {
            // set the owning side to null (unless already changed)
            if ($watchListTrigger->getWatchList() === $this) {
                $watchListTrigger->setWatchList(null);
            }
        }

        return $this;
    }

    public function getConnector(): ?Connector
    {
        return $this->connector;
    }

    public function setConnector(?Connector $connector): static
    {
        $this->connector = $connector;

        return $this;
    }
}
