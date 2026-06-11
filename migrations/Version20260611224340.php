<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260611224340 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE decision (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, external_reference VARCHAR(255) DEFAULT NULL, status VARCHAR(255) NOT NULL, type VARCHAR(255) NOT NULL, balance_delta DOUBLE PRECISION NOT NULL, reason VARCHAR(255) NOT NULL, idempotency_key VARCHAR(255) NOT NULL, decided_on DATE NOT NULL, request_id INTEGER NOT NULL, CONSTRAINT FK_84ACBE48427EB8A5 FOREIGN KEY (request_id) REFERENCES leave_request (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_84ACBE48427EB8A5 ON decision (request_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_decision_key ON decision (idempotency_key)');
        $this->addSql('CREATE TABLE employee (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, employment_start_date DATE NOT NULL, working_days_per_week INTEGER NOT NULL, federal_state VARCHAR(2) NOT NULL, contractual_leave_days INTEGER NOT NULL, employment_end_date DATE DEFAULT NULL)');
        $this->addSql('CREATE TABLE leave_balance (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, year INTEGER NOT NULL, carried_over_days DOUBLE PRECISION NOT NULL, carryover_expires_on DATE DEFAULT NULL, used_days DOUBLE PRECISION NOT NULL, employee_id INTEGER NOT NULL, CONSTRAINT FK_EAAB67198C03F15C FOREIGN KEY (employee_id) REFERENCES employee (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_EAAB67198C03F15C ON leave_balance (employee_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_employee_year ON leave_balance (employee_id, year)');
        $this->addSql('CREATE TABLE leave_request (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, status VARCHAR(255) NOT NULL, half_day_start BOOLEAN NOT NULL, half_day_end BOOLEAN NOT NULL, medical_certificate BOOLEAN NOT NULL, decided_at DATE DEFAULT NULL, decision_reason VARCHAR(255) DEFAULT NULL, external_reference VARCHAR(255) DEFAULT NULL, type VARCHAR(255) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, submitted_at DATE NOT NULL, employee_id INTEGER NOT NULL, CONSTRAINT FK_7DC8F7788C03F15C FOREIGN KEY (employee_id) REFERENCES employee (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_7DC8F7788C03F15C ON leave_request (employee_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE decision');
        $this->addSql('DROP TABLE employee');
        $this->addSql('DROP TABLE leave_balance');
        $this->addSql('DROP TABLE leave_request');
    }
}
