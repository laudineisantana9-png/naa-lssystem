-- =========================================================
-- NAA · Núcleo de Avaliação da Aprendizagem
-- Banco MySQL / MariaDB · v7.3.0
-- Identidade e dados independentes do sistema ASSEC.
-- NAA 7.3: datasets CNCA/SABE podem ser persistidos no naa_store com chaves dataset:*; JSON permanece como espelho.
-- =========================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS naa_store (
  store_key VARCHAR(120) NOT NULL,
  payload LONGTEXT NOT NULL,
  checksum CHAR(64) DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (store_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS naa_configuracoes (
  chave VARCHAR(120) NOT NULL,
  valor LONGTEXT NULL,
  tipo VARCHAR(24) NOT NULL DEFAULT 'string',
  descricao VARCHAR(255) NULL,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS naa_auditoria (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id VARCHAR(80) NULL,
  acao VARCHAR(120) NOT NULL,
  entidade VARCHAR(120) NULL,
  entidade_id VARCHAR(160) NULL,
  detalhes LONGTEXT NULL,
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(500) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_naa_aud_usuario (usuario_id),
  KEY idx_naa_aud_acao (acao),
  KEY idx_naa_aud_criado (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS naa_importacoes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dataset_id VARCHAR(120) NULL,
  segmento VARCHAR(60) NULL,
  camada VARCHAR(60) NULL,
  avaliacao VARCHAR(100) NULL,
  ano_referencia SMALLINT NULL,
  ciclo VARCHAR(80) NULL,
  arquivo VARCHAR(255) NULL,
  linhas INT UNSIGNED NOT NULL DEFAULT 0,
  usuario_id VARCHAR(80) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_naa_imp_segmento (segmento, ano_referencia),
  KEY idx_naa_imp_dataset (dataset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO naa_configuracoes (chave, valor, tipo, descricao) VALUES
('sistema_nome','NAA — Plataforma Municipal de Avaliação da Aprendizagem','string','Nome oficial da plataforma'),
('sistema_subtitulo','Núcleo de Avaliação da Aprendizagem','string','Identidade institucional'),
('tema_cor','#0066CC','color','Cor primária no padrão visual ASSEC'),
('tema_cor_secundaria','#00D4AA','color','Cor secundária no padrão visual ASSEC'),
('borda_sistema','12','number','Raio global dos componentes em pixels'),
('tema_padrao','light','string','Tema padrão da interface')
ON DUPLICATE KEY UPDATE chave = VALUES(chave);
