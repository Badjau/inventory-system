-- Inventory owns only inv_* tables. HR tables are read only to this app.
CREATE TABLE IF NOT EXISTS inv_devices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_key VARCHAR(120) NULL UNIQUE,
    device_type VARCHAR(30) NOT NULL,
    name VARCHAR(255) NOT NULL,
    serial_number VARCHAR(255) NULL,
    asset_tag VARCHAR(255) NULL,
    device_condition VARCHAR(30) NOT NULL DEFAULT 'Unknown',
    location VARCHAR(255) NULL,
    location_branch_id BIGINT UNSIGNED NULL,
    holder_kind VARCHAR(20) NOT NULL DEFAULT 'unassigned',
    hr_employee_id BIGINT UNSIGNED NULL,
    holder_name VARCHAR(255) NULL,
    department VARCHAR(255) NULL,
    department_id BIGINT UNSIGNED NULL,
    branch VARCHAR(255) NULL,
    job_title VARCHAR(255) NULL,
    handover_date DATE NULL,
    specifications_json JSON NOT NULL,
    source_json JSON NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX inv_serial_idx (serial_number),
    INDEX inv_asset_idx (asset_tag),
    INDEX inv_employee_idx (hr_employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(30) NOT NULL,
    message TEXT NOT NULL,
    handover_date DATE NULL,
    snapshot_json JSON NOT NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT inv_events_device_fk FOREIGN KEY (device_id) REFERENCES inv_devices(id),
    INDEX inv_event_device_idx (device_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
