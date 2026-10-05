<?php

declare(strict_types=1);

namespace DRESearch\Indexer;

use Doctrine\DBAL\Connection;
use DRESearch\Settings\SearchProfile;

final class MapperFactory
{
    public function __construct(private readonly Connection $connection, private readonly SearchProfile $profile)
    {
    }

    public function create(): MapperInterface
    {
        if ($this->profile->kind() === 'project') {
            return new ProjectMapper($this->profile);
        }
        if ($this->profile->kind() === 'publication') {
            return new PublicationMapper($this->profile);
        }
        if ($this->profile->kind() === 'podcast') {
            return new PodcastMapper($this->profile);
        }
        if ($this->profile->kind() === 'video') {
            return new VideoMapper($this->profile);
        }
        if ($this->profile->kind() === 'person') {
            return new PersonMapper($this->profile);
        }
        if ($this->profile->kind() === 'section') {
            return new SectionMapper($this->profile);
        }
        if ($this->profile->kind() === 'organisation') {
            return new OrganisationMapper($this->profile);
        }
        if ($this->profile->kind() === 'term') {
            return new TermMapper($this->profile);
        }

        $auth = new AuthorityResolver($this->connection, $this->profile);
        $auth->load();
        return new ResearchItemMapper($auth, $this->profile);
    }
}
