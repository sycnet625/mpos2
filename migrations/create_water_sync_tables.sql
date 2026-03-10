CREATE TABLE IF NOT EXISTS water_sync_clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  device_name VARCHAR(190) NOT NULL UNIQUE,
  last_seen_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS water_sync_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  local_event_id BIGINT NOT NULL,
  event_type VARCHAR(60) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  created_at_ms BIGINT NOT NULL,
  received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_client_event (client_id, local_event_id),
  KEY idx_client_received (client_id, received_at),
  CONSTRAINT fk_water_sync_client FOREIGN KEY (client_id) REFERENCES water_sync_clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS water_sync_access_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  action VARCHAR(32) NOT NULL,
  client_ip VARCHAR(120) NOT NULL,
  user_agent VARCHAR(255) NULL,
  content_length INT NOT NULL DEFAULT 0,
  device_name VARCHAR(190) NULL,
  status_code INT NULL,
  received_count INT NULL,
  inserted_count INT NULL,
  discarded_count INT NULL,
  payload_sha256 VARCHAR(128) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created_at (created_at),
  KEY idx_device_name (device_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
