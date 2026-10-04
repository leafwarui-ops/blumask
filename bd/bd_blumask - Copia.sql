create table usuario(
id_usuario int primary key auto_increment,
email varchar(100),
nome_de_exibicao varchar(100),
senha varchar(255),
nome_de_usuario varchar(100) unique,
descricao text,
banner varchar(200),
foto_perfil varchar(200),
is_admin tinyint(1) not null default 0,
suspenso_ate datetime null default null
) engine=InnoDB;

create table if not exists limite_publicacao_usuario(
id_usuario int not null,
tipo_publicacao enum('post', 'comentario') not null,
data_ultima_publicacao datetime not null,
primary key (id_usuario, tipo_publicacao),
constraint fk_limite_publicacao_usuario foreign key (id_usuario)
references usuario(id_usuario) on delete cascade
) engine=InnoDB;

alter table usuario
add column if not exists is_admin tinyint(1) not null default 0;

alter table usuario
add column if not exists suspenso_ate datetime null default null;

-- Se seu banco já foi criado com um índice único em nome_de_exibicao, execute:
-- ALTER TABLE usuario DROP INDEX nome_de_exibicao;

create table comunidade(
id_comunidade int primary key auto_increment,
data_criacao date,
descricao text,
nome varchar(150),
id_usuario int,
imagem varchar(200),

foreign key (id_usuario) references usuario(id_usuario)
) engine=InnoDB;

create table post(
id_post int primary key auto_increment,
id_comunidade int,
Data_post datetime,
conteudo text,
id_usuario int,
assunto varchar(150),
imagem varchar(255) null,

foreign key (id_comunidade) references comunidade(id_comunidade),
foreign key (id_usuario) references usuario(id_usuario)
) engine=InnoDB;



create table membro_comunidade(
id_membro_comunidade int primary key auto_increment,
id_usuario int,
id_comunidade int,
cargo int,
data_entrada date,

foreign key (id_usuario) references usuario(id_usuario),
foreign key (id_comunidade) references comunidade(id_comunidade)
) engine=InnoDB;

create table if not exists banimento_comunidade(
id_banimento int primary key auto_increment,
id_comunidade int not null,
id_usuario int not null,
id_usuario_baniu int not null,
data_banimento datetime not null default current_timestamp,
unique key uq_banimento_comunidade_usuario (id_comunidade, id_usuario),
key idx_banimento_usuario (id_usuario)
);

create table if not exists mensagem_administrativa(
id_mensagem bigint unsigned primary key auto_increment,
id_destinatario int not null,
id_remetente int null,
mensagem text not null,
enviada_em datetime not null default current_timestamp,
fechada_em datetime null default null,
key idx_mensagem_destinatario (id_destinatario, fechada_em, enviada_em),
constraint fk_mensagem_admin_destinatario foreign key (id_destinatario)
references usuario(id_usuario) on delete cascade,
constraint fk_mensagem_admin_remetente foreign key (id_remetente)
references usuario(id_usuario) on delete set null
) engine=InnoDB;

create table comentario(
id_comentario int primary key auto_increment,
id_usuario int,
id_post int,
conteudo text,
data_comentario datetime,

foreign key (id_usuario) references usuario(id_usuario),
foreign key (id_post) references post(id_post)
) engine=InnoDB;

create table if not exists notificacao(
    id_notificacao int primary key auto_increment,
    id_usuario int not null,
    id_remetente int null,
    id_post int null,
    id_comentario int null,
    tipo varchar(40) not null default 'comentario',
    mensagem text not null,
    lida tinyint(1) not null default 0,
    criada_em datetime not null default current_timestamp,
    unique key uq_notificacao_comentario (id_usuario, id_post, id_comentario, tipo),
    key idx_notificacao_usuario_lida (id_usuario, lida, criada_em),
    key idx_notificacao_post (id_post),
    constraint fk_notificacao_destinatario foreign key (id_usuario) references usuario(id_usuario) on delete cascade,
    constraint fk_notificacao_remetente foreign key (id_remetente) references usuario(id_usuario) on delete set null,
    constraint fk_notificacao_post foreign key (id_post) references post(id_post) on delete cascade,
    constraint fk_notificacao_comentario foreign key (id_comentario) references comentario(id_comentario) on delete cascade
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;

-- A tabela referenciada precisa usar InnoDB para aceitar a chave estrangeira.
ALTER TABLE usuario ENGINE=InnoDB;
ALTER TABLE comunidade ENGINE=InnoDB;
ALTER TABLE post ENGINE=InnoDB;
ALTER TABLE comentario ENGINE=InnoDB;

create table if not exists limite_mencao_admin (
    id_usuario int not null primary key,
    ultima_notificacao datetime not null,
    constraint fk_limite_mencao_admin_usuario foreign key (id_usuario)
        references usuario(id_usuario) on delete cascade
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci;

create table if not exists curtida(
id_curtida int primary key auto_increment,
id_usuario int,
id_post int,

foreign key (id_usuario) references usuario(id_usuario),
foreign key (id_post) references post(id_post)
) engine=InnoDB;

-- Adiciona as colunas apenas quando ainda nao existem (compativel com MySQL 5.7).
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuario' AND COLUMN_NAME = 'id_post_fixado') = 0,
    'ALTER TABLE usuario ADD COLUMN id_post_fixado INT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comunidade' AND COLUMN_NAME = 'id_post_fixado') = 0,
    'ALTER TABLE comunidade ADD COLUMN id_post_fixado INT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'usuario' AND CONSTRAINT_NAME = 'id_post_fixado' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0,
    'ALTER TABLE usuario ADD CONSTRAINT id_post_fixado FOREIGN KEY (id_post_fixado) REFERENCES post(id_post)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'comunidade' AND CONSTRAINT_NAME = 'id_comu_post_fixado' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0,
    'ALTER TABLE comunidade ADD CONSTRAINT id_comu_post_fixado FOREIGN KEY (id_post_fixado) REFERENCES post(id_post)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Permitir fixar um comentário em um post
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'post' AND COLUMN_NAME = 'id_comentario_fixado') = 0,
    'ALTER TABLE post ADD COLUMN id_comentario_fixado INT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuario' AND COLUMN_NAME = 'id_comentario_fixado') = 0,
    'ALTER TABLE usuario ADD COLUMN id_comentario_fixado INT',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'post' AND CONSTRAINT_NAME = 'fk_post_comentario_fixado' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0,
    'ALTER TABLE post ADD CONSTRAINT fk_post_comentario_fixado FOREIGN KEY (id_comentario_fixado) REFERENCES comentario(id_comentario)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'usuario' AND CONSTRAINT_NAME = 'fk_usuario_comentario_fixado' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0,
    'ALTER TABLE usuario ADD CONSTRAINT fk_usuario_comentario_fixado FOREIGN KEY (id_comentario_fixado) REFERENCES comentario(id_comentario)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

create table if not exists perfil_post_fixado(
id_usuario int not null,
id_post int not null,
data_fixacao timestamp default current_timestamp,
primary key (id_usuario, id_post),
foreign key (id_usuario) references usuario(id_usuario),
foreign key (id_post) references post(id_post)
) engine=InnoDB;

create table if not exists perfil_comentario_fixado(
id_usuario int not null,
id_comentario int not null,
data_fixacao timestamp default current_timestamp,
primary key (id_usuario, id_comentario),
foreign key (id_usuario) references usuario(id_usuario),
foreign key (id_comentario) references comentario(id_comentario)
) engine=InnoDB;



ALTER TABLE usuario CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE comunidade CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE post CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE comentario CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;