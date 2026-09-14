CREATE TABLE IF NOT EXISTS ls_runtime_records (
 app_id VARCHAR(80) NOT NULL, entity VARCHAR(120) NOT NULL, record_id VARCHAR(190) NOT NULL,
 data_json LONGTEXT NULL, version BIGINT UNSIGNED NOT NULL DEFAULT 1, updated_at DATETIME NOT NULL,
 deleted TINYINT(1) NOT NULL DEFAULT 0,
 PRIMARY KEY(app_id,entity,record_id), KEY idx_ls_records_updated(app_id,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ls_runtime_changes (
 seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, app_id VARCHAR(80) NOT NULL, entity VARCHAR(120) NOT NULL,
 record_id VARCHAR(190) NOT NULL, operation ENUM('upsert','delete') NOT NULL, data_json LONGTEXT NULL,
 version BIGINT UNSIGNED NOT NULL, device_id VARCHAR(120) NOT NULL, updated_at DATETIME NOT NULL,
 PRIMARY KEY(seq), KEY idx_ls_changes_pull(app_id,seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ls_runtime_acks (
 app_id VARCHAR(80) NOT NULL, change_id VARCHAR(80) NOT NULL, device_id VARCHAR(120) NOT NULL, created_at DATETIME NOT NULL,
 PRIMARY KEY(app_id,change_id), KEY idx_ls_acks_device(app_id,device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ls_runtime_devices (
 app_id VARCHAR(80) NOT NULL, device_id VARCHAR(120) NOT NULL, first_seen DATETIME NOT NULL, last_seen DATETIME NOT NULL,
 PRIMARY KEY(app_id,device_id), KEY idx_ls_devices_seen(app_id,last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
