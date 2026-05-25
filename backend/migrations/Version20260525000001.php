<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260525000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tenant memberships table for multi-tenant access control';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tenant_memberships (id UUID NOT NULL, user_id UUID NOT NULL, tenant_id UUID NOT NULL, role VARCHAR(50) NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_tenant_membership_user_tenant ON tenant_memberships (user_id, tenant_id)');
        $this->addSql('CREATE INDEX idx_tenant_membership_user ON tenant_memberships (user_id)');
        $this->addSql('CREATE INDEX idx_tenant_membership_tenant ON tenant_memberships (tenant_id)');
        $this->addSql('CREATE INDEX idx_tenant_membership_active ON tenant_memberships (is_active)');
        $this->addSql('ALTER TABLE tenant_memberships ADD CONSTRAINT FK_TENANT_MEMBERSHIPS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE tenant_memberships ADD CONSTRAINT FK_TENANT_MEMBERSHIPS_TENANT FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tenant_memberships');
    }
}
