-- COLD CASE – Schema v1 (MySQL 5.7+/MariaDB 10.3+, utf8mb4, InnoDB)
CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  username VARCHAR(32) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('player','editor','admin') NOT NULL DEFAULT 'player',
  avatar VARCHAR(32) NOT NULL DEFAULT 'a1',
  email_verified_at DATETIME NULL,
  consent_at DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  deleted_at DATETIME NULL,
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('verify','reset') NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  KEY ix_tokens_hash (token_hash),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE rate_limits (
  rkey VARCHAR(120) NOT NULL,
  window_start INT UNSIGNED NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (rkey, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

CREATE TABLE cases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(64) NOT NULL,
  case_number VARCHAR(16) NOT NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  published_version_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_cases_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fallinhalt (inkl. Lösung) liegt NUR hier und wird nie roh ausgeliefert.
CREATE TABLE case_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id BIGINT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL,
  content LONGTEXT NOT NULL,
  content_hash CHAR(64) NOT NULL,
  validation_report LONGTEXT NULL,
  validation_ok TINYINT(1) NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  published_at DATETIME NULL,
  UNIQUE KEY uq_case_version (case_id, version),
  CONSTRAINT fk_cv_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE game_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id BIGINT UNSIGNED NOT NULL,
  case_version_id BIGINT UNSIGNED NOT NULL,
  owner_id BIGINT UNSIGNED NOT NULL,
  mode ENUM('solo','team') NOT NULL DEFAULT 'solo',
  ranked TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('active','paused','solved','failed','abandoned') NOT NULL DEFAULT 'active',
  invite_code CHAR(8) NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  started_at DATETIME NOT NULL,
  last_activity_at DATETIME NOT NULL,
  active_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  completed_at DATETIME NULL,
  hints_used TINYINT UNSIGNED NOT NULL DEFAULT 0,
  false_accusations TINYINT UNSIGNED NOT NULL DEFAULT 0,
  score_points TINYINT UNSIGNED NULL,
  UNIQUE KEY uq_sessions_invite (invite_code),
  KEY ix_sessions_owner (owner_id, case_id),
  CONSTRAINT fk_gs_case FOREIGN KEY (case_id) REFERENCES cases(id),
  CONSTRAINT fk_gs_cv FOREIGN KEY (case_version_id) REFERENCES case_versions(id),
  CONSTRAINT fk_gs_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE session_members (
  session_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(24) NOT NULL DEFAULT 'ermittler',
  joined_at DATETIME NOT NULL,
  PRIMARY KEY (session_id, user_id),
  KEY ix_members_user (user_id),
  CONSTRAINT fk_sm_s FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_sm_u FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Entdeckte Beweise, gestellte Fragen, ausgeführte Aktionen, Flags, untersuchte Hotspots.
CREATE TABLE session_progress (
  session_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('evidence','asked','action','flag','hotspot') NOT NULL,
  ref VARCHAR(80) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (session_id, kind, ref),
  CONSTRAINT fk_sp_s FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Idempotenz + Audit-Trail aller Aktionen
CREATE TABLE session_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  client_action_id VARCHAR(64) NOT NULL,
  type VARCHAR(32) NOT NULL,
  payload TEXT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_event_idem (session_id, client_action_id),
  CONSTRAINT fk_se_s FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE session_notes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  deleted TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY ix_notes_s (session_id),
  CONSTRAINT fk_sn_s FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE session_board_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('evidence','suspect','note') NOT NULL,
  ref VARCHAR(80) NULL,
  label VARCHAR(240) NOT NULL DEFAULT '',
  x INT NOT NULL DEFAULT 40,
  y INT NOT NULL DEFAULT 40,
  color VARCHAR(16) NOT NULL DEFAULT 'neutral',
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  deleted TINYINT(1) NOT NULL DEFAULT 0,
  KEY ix_bi_s (session_id),
  CONSTRAINT fk_bi_s FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE session_board_connections (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  from_item BIGINT UNSIGNED NOT NULL,
  to_item BIGINT UNSIGNED NOT NULL,
  label VARCHAR(120) NOT NULL DEFAULT '',
  color VARCHAR(16) NOT NULL DEFAULT 'red',
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  deleted TINYINT(1) NOT NULL DEFAULT 0,
  KEY ix_bc_s (session_id),
  CONSTRAINT fk_bc_s FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Eigene Zeitachsen-Einträge und Markierungen (Widerspruch/Lücke) auf Fall-Ereignissen
CREATE TABLE session_timeline_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  event_ref VARCHAR(64) NULL,
  at_time CHAR(5) NULL,
  title VARCHAR(200) NOT NULL DEFAULT '',
  note TEXT NULL,
  flagged TINYINT(1) NOT NULL DEFAULT 0,
  deleted TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY ix_tl_s (session_id),
  CONSTRAINT fk_tl_s FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE session_hints (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  level TINYINT UNSIGNED NOT NULL,
  hint_ref VARCHAR(64) NULL,
  text TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_sh_s FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE session_accusations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  culprit VARCHAR(64) NOT NULL,
  motive VARCHAR(64) NULL,
  method VARCHAR(64) NULL,
  evidence_json TEXT NULL,
  timeline_json TEXT NULL,
  culprit_correct TINYINT(1) NOT NULL,
  score TINYINT UNSIGNED NULL,
  result_json TEXT NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_sa_s FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE session_pauses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  paused_at DATETIME NOT NULL,
  resumed_at DATETIME NULL,
  CONSTRAINT fk_spz_s FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE leaderboards (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  mode ENUM('solo','team') NOT NULL,
  players TINYINT UNSIGNED NOT NULL DEFAULT 1,
  hints_used TINYINT UNSIGNED NOT NULL,
  false_accusations TINYINT UNSIGNED NOT NULL,
  active_seconds INT UNSIGNED NOT NULL,
  score_seconds INT UNSIGNED NOT NULL,
  score_points TINYINT UNSIGNED NOT NULL,
  completed_at DATETIME NOT NULL,
  UNIQUE KEY uq_lb_session (session_id),
  KEY ix_lb_rank (case_id, mode, score_seconds),
  CONSTRAINT fk_lb_c FOREIGN KEY (case_id) REFERENCES cases(id),
  CONSTRAINT fk_lb_u FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE achievements (
  code VARCHAR(40) PRIMARY KEY,
  title VARCHAR(80) NOT NULL,
  description VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_achievements (
  user_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(40) NOT NULL,
  session_id BIGINT UNSIGNED NULL,
  earned_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, code),
  CONSTRAINT fk_ua_u FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ua_a FOREIGN KEY (code) REFERENCES achievements(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tv_pairings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code CHAR(6) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  session_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  view_json TEXT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  UNIQUE KEY uq_tv_token (token_hash),
  KEY ix_tv_code (code),
  CONSTRAINT fk_tv_s FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE media_assets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id BIGINT UNSIGNED NULL,
  path VARCHAR(255) NOT NULL,
  mime VARCHAR(80) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  alt VARCHAR(255) NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_media_path (path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(48) NOT NULL,
  target VARCHAR(120) NULL,
  ip_hash CHAR(16) NULL,
  detail TEXT NULL,
  created_at DATETIME NOT NULL,
  KEY ix_audit_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO achievements (code,title,description) VALUES
 ('first_case','Erster Fall gelöst','Du hast deinen ersten Fall gelöst.'),
 ('no_hints','Ohne fremde Hilfe','Fall ohne Hinweise abgeschlossen.'),
 ('three_cases','Routinier','Drei Fälle gelöst.'),
 ('perfect_chain','Perfekte Beweiskette','Fall mit voller Punktzahl gelöst.'),
 ('all_suspects','Kein Stein auf dem anderen','Alle Verdächtigen befragt.'),
 ('hard_case','Harte Nuss','Einen Fall der Stufe 4 oder höher gelöst.'),
 ('team_case','Teamarbeit','Teamfall erfolgreich abgeschlossen.');
