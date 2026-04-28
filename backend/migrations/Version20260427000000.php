<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create users, tenants, products and playbooks tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE users (id UUID NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_USERS_EMAIL ON users (email)');

        $this->addSql('CREATE TABLE tenants (id UUID NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(180) NOT NULL, business_context TEXT NOT NULL, tone VARCHAR(120) DEFAULT NULL, sales_policy JSON NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_TENANTS_SLUG ON tenants (slug)');

        $this->addSql('CREATE TABLE products (id UUID NOT NULL, tenant_id UUID NOT NULL, name VARCHAR(255) NOT NULL, description TEXT NOT NULL, value_proposition TEXT NOT NULL, sales_policy JSON NOT NULL, is_active BOOLEAN NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_PRODUCTS_TENANT_ID ON products (tenant_id)');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_PRODUCTS_TENANT FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE playbooks (id UUID NOT NULL, tenant_id UUID NOT NULL, product_id UUID DEFAULT NULL, name VARCHAR(255) NOT NULL, config JSON NOT NULL, is_active BOOLEAN NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_PLAYBOOKS_TENANT_ID ON playbooks (tenant_id)');
        $this->addSql('CREATE INDEX IDX_PLAYBOOKS_PRODUCT_ID ON playbooks (product_id)');
        $this->addSql('ALTER TABLE playbooks ADD CONSTRAINT FK_PLAYBOOKS_TENANT FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE playbooks ADD CONSTRAINT FK_PLAYBOOKS_PRODUCT FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE playbooks');
        $this->addSql('DROP TABLE products');
        $this->addSql('DROP TABLE tenants');
        $this->addSql('DROP TABLE users');
    }
}
