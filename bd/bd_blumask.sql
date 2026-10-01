create database bd_blumask character set utf8mb4 collate utf8mb4_unicode_ci;
use bd_blumask;

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
);

create table if not exists limite_publicacao_usuario(
id_usuario int not null,
tipo_publicacao enum('post', 'comentario') not null,
data_ultima_publicacao datetime not null,
primary key (id_usuario, tipo_publicacao),
constraint fk_limite_publicacao_usuario foreign key (id_usuario)
references usuario(id_usuario) on delete cascade
);

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
);

create table post(
id_post int primary key auto_increment,
id_comunidade int,
Data_post datetime,
conteudo text,
id_usuario int,
assunto varchar(150),

foreign key (id_comunidade) references comunidade(id_comunidade),
foreign key (id_usuario) references usuario(id_usuario)
);



create table membro_comunidade(
id_membro_comunidade int primary key auto_increment,
id_usuario int,
id_comunidade int,
cargo int,
data_entrada date,

foreign key (id_usuario) references usuario(id_usuario),
foreign key (id_comunidade) references comunidade(id_comunidade)
);

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
);

create table comentario(
id_comentario int primary key auto_increment,
id_usuario int,
id_post int,
conteudo text,
data_comentario datetime,

foreign key (id_usuario) references usuario(id_usuario),
foreign key (id_post) references post(id_post)
);

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
) charset=utf8mb4 collate=utf8mb4_unicode_ci;

create table curtida(
id_curtida int primary key auto_increment,
id_usuario int,
id_post int,

foreign key (id_usuario) references usuario(id_usuario),
foreign key (id_post) references post(id_post)
);

alter table usuario
add id_post_fixado int;

alter table comunidade
add id_post_fixado int;

alter table usuario
add constraint id_post_fixado
foreign key (id_post_fixado) references post(id_post);

alter table comunidade
add constraint id_comu_post_fixado
foreign key (id_post_fixado) references post(id_post);

-- Permitir fixar um comentário em um post
alter table post
add id_comentario_fixado int;

alter table post
add constraint fk_post_comentario_fixado
foreign key (id_comentario_fixado) references comentario(id_comentario);

alter table usuario
add id_comentario_fixado int;

alter table usuario
add constraint fk_usuario_comentario_fixado
foreign key (id_comentario_fixado) references comentario(id_comentario);

create table perfil_post_fixado(
id_usuario int not null,
id_post int not null,
data_fixacao timestamp default current_timestamp,
primary key (id_usuario, id_post),
foreign key (id_usuario) references usuario(id_usuario),
foreign key (id_post) references post(id_post)
);

create table perfil_comentario_fixado(
id_usuario int not null,
id_comentario int not null,
data_fixacao timestamp default current_timestamp,
primary key (id_usuario, id_comentario),
foreign key (id_usuario) references usuario(id_usuario),
foreign key (id_comentario) references comentario(id_comentario)
);