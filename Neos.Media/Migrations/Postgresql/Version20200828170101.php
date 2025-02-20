<?php
declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fix paths to static icons still pointing to TYPO3.Media
 */
class Version20200828170101 extends AbstractMigration
{
    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Fix paths to static icons still pointing to TYPO3.Media';
    }

    /**
     * @param Schema $schema
     * @return void
     * @throws DBALException
     */
    public function up(Schema $schema): void
    {
        $this->abortIf(!($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform), 'Migration can only be executed safely on "postgresql".');

        $this->addSql("UPDATE neos_media_domain_model_thumbnail SET staticresource = REPLACE(staticresource, 'resource://TYPO3.Media/Public/Icons/512px/', 'resource://Neos.Media/Public/IconSets/vivid/')");
        $this->addSql("UPDATE neos_media_domain_model_thumbnail SET staticresource = REPLACE(staticresource, '.png', '.svg')");
    }

    /**
     * @param Schema $schema
     * @return void
     * @throws DBALException
     */
    public function down(Schema $schema): void
    {
        $this->abortIf(!($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform), 'Migration can only be executed safely on "postgresql".');

        $this->addSql("UPDATE neos_media_domain_model_thumbnail SET staticresource = REPLACE(staticresource, 'resource://Neos.Media/Public/IconSets/vivid/', 'resource://TYPO3.Media/Public/Icons/512px/')");
        $this->addSql("UPDATE neos_media_domain_model_thumbnail SET staticresource = REPLACE(staticresource, '.svg', '.png')");
    }
}
