<?php
declare(strict_types=1);

namespace Oasebos\Participations\Database;

final class Schema
{
    public function create(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;
        $sql = [];
        $sql[] = "CREATE TABLE {$p}oasebos_projects (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(200) NOT NULL,
            location VARCHAR(255) NULL,
            description LONGTEXT NULL,
            total_hectares DECIMAL(12,4) NOT NULL DEFAULT 0,
            available_hectares DECIMAL(12,4) NOT NULL DEFAULT 0,
            unit_size DECIMAL(12,4) NOT NULL DEFAULT 1,
            price_per_unit DECIMAL(12,2) NOT NULL DEFAULT 0,
            currency CHAR(3) NOT NULL DEFAULT 'EUR',
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            agreement_template_id BIGINT UNSIGNED NULL,
            certificate_template_id BIGINT UNSIGNED NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY slug (slug), KEY status (status), KEY sort_order (sort_order)
        ) $charset";
        $sql[] = "CREATE TABLE {$p}oasebos_templates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(30) NOT NULL,
            name VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NULL,
            content LONGTEXT NOT NULL,
            css LONGTEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id), KEY type_status (type,status)
        ) $charset";
        $sql[] = "CREATE TABLE {$p}oasebos_participations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            participation_number VARCHAR(80) NOT NULL,
            project_id BIGINT UNSIGNED NOT NULL,
            project_snapshot LONGTEXT NOT NULL,
            participant_first_name VARCHAR(120) NOT NULL,
            participant_last_name VARCHAR(120) NOT NULL,
            participant_email VARCHAR(190) NOT NULL,
            participant_phone VARCHAR(80) NULL,
            participant_address VARCHAR(255) NULL,
            participant_postcode VARCHAR(40) NULL,
            participant_city VARCHAR(120) NULL,
            participant_country VARCHAR(2) NULL,
            units INT UNSIGNED NOT NULL,
            unit_size DECIMAL(12,4) NOT NULL,
            total_hectares DECIMAL(12,4) NOT NULL,
            price_per_unit DECIMAL(12,2) NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'EUR',
            is_test TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            mollie_payment_id VARCHAR(80) NULL,
            agreement_template_id BIGINT UNSIGNED NULL,
            certificate_template_id BIGINT UNSIGNED NULL,
            agreement_template_snapshot LONGTEXT NULL,
            certificate_template_snapshot LONGTEXT NULL,
            pdf_path VARCHAR(500) NULL,
            paid_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY participation_number (participation_number), UNIQUE KEY mollie_payment_id (mollie_payment_id), KEY project_id (project_id), KEY status (status), KEY is_test (is_test), KEY participant_email (participant_email), KEY created_at (created_at)
        ) $charset";
        $sql[] = "CREATE TABLE {$p}oasebos_participation_land_units (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            participation_id BIGINT UNSIGNED NOT NULL,
            project_id BIGINT UNSIGNED NOT NULL,
            land_unit_number VARCHAR(100) NOT NULL,
            unit_index INT UNSIGNED NOT NULL,
            hectares DECIMAL(12,4) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'paid',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY land_unit_number (land_unit_number), UNIQUE KEY participation_unit (participation_id,unit_index), KEY participation_id (participation_id), KEY project_id (project_id), KEY status (status), KEY created_at (created_at)
        ) $charset";
        $sql[] = "CREATE TABLE {$p}oasebos_donations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            donation_number VARCHAR(80) NOT NULL,
            donor_first_name VARCHAR(120) NULL,
            donor_last_name VARCHAR(120) NULL,
            donor_email VARCHAR(190) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'EUR',
            message TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            mollie_payment_id VARCHAR(80) NULL,
            paid_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY donation_number (donation_number), UNIQUE KEY mollie_payment_id (mollie_payment_id), KEY status (status), KEY donor_email (donor_email), KEY created_at (created_at)
        ) $charset";
        $sql[] = "CREATE TABLE {$p}oasebos_recurring_donations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            subscription_number VARCHAR(80) NOT NULL,
            donor_first_name VARCHAR(120) NULL,
            donor_last_name VARCHAR(120) NULL,
            donor_email VARCHAR(190) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'EUR',
            interval_value VARCHAR(40) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending_mandate',
            mollie_customer_id VARCHAR(80) NULL,
            mollie_mandate_id VARCHAR(80) NULL,
            mollie_subscription_id VARCHAR(80) NULL,
            initial_payment_id VARCHAR(80) NULL,
            last_payment_id VARCHAR(80) NULL,
            last_payment_status VARCHAR(40) NULL,
            next_payment_at DATETIME NULL,
            started_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY subscription_number (subscription_number), KEY status (status), KEY donor_email (donor_email), KEY initial_payment_id (initial_payment_id), KEY mollie_customer_id (mollie_customer_id), KEY mollie_subscription_id (mollie_subscription_id), KEY created_at (created_at)
        ) $charset";
        $sql[] = "CREATE TABLE {$p}oasebos_payment_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(40) NOT NULL,
            entity_id BIGINT UNSIGNED NULL,
            mollie_payment_id VARCHAR(80) NULL,
            mollie_customer_id VARCHAR(80) NULL,
            event_type VARCHAR(80) NOT NULL,
            status VARCHAR(40) NULL,
            payload LONGTEXT NULL,
            message TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id), KEY entity (entity_type,entity_id), KEY mollie_payment_id (mollie_payment_id), KEY event_type (event_type), KEY created_at (created_at)
        ) $charset";
        $sql[] = "CREATE TABLE {$p}oasebos_email_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(40) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            recipient_email VARCHAR(190) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            status VARCHAR(30) NOT NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id), KEY entity (entity_type,entity_id), KEY recipient_email (recipient_email), KEY created_at (created_at)
        ) $charset";
        foreach ($sql as $statement) {
            dbDelta($statement);
        }
    }
}
