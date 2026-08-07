-- note.my schema — MariaDB 10.5+ only (uses DELETE ... RETURNING).
-- charset=binary on `notes` is deliberate: payload is raw ciphertext bytes,
-- and id_hash is hex, so no column here ever needs collation awareness.

-- 笔记密文。这是唯一存放用户数据的地方。
-- 列就是这四个，不多不少。禁止新增 IP / UA / Referer / 创建者标识。
CREATE TABLE IF NOT EXISTS notes (
  -- CHARACTER SET 必须显式写出。表级 DEFAULT CHARSET=binary 会把裸 CHAR(64)
  -- 静默转成 BINARY(64)（定长、0x00 右填充）。哈希恒为 64 字节所以行为上无害，
  -- 但意图不明确。ascii_bin 给出大小写敏感的精确匹配，正是小写 hex 需要的。
  id_hash    CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  payload    BLOB      NOT NULL,          -- 解码后的二进制密文（iv||ct||tag）
  expires_at DATETIME  NOT NULL,
  created_at DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_hash),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=binary;

-- 仅日粒度聚合，不含任何单条笔记信息。
CREATE TABLE IF NOT EXISTS daily_stats (
  stat_date     DATE PRIMARY KEY,
  notes_created INT UNSIGNED NOT NULL DEFAULT 0,
  notes_read    INT UNSIGNED NOT NULL DEFAULT 0,
  notes_expired INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- 滥用举报。只存 ID 哈希，便于封禁但无法据此访问笔记。
CREATE TABLE IF NOT EXISTS abuse_reports (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  note_id_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  reason       VARCHAR(32) NOT NULL,
  created_at   DATETIME    NOT NULL,
  KEY (note_id_hash)
) ENGINE=InnoDB;
