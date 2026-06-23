create database bd_blumask;
use bd_blumask;

create table usuario(
id_usuario int primary key auto_increment,
email varchar(100),
nome_de_exibicao varchar(100),
senha varchar(25),
nome_de_usuario varchar(100) unique,
descricao text,
banner blob,
foto_perfil blob
);

create table comunidade(
id_comunidade int primary key auto_increment,
data_criacao date,
descricao text,
nome varchar(150),
id_usuario int,
imagem blob,

foreign key (id_usuario) references usuario(id_usuario)
);

create table post(
id_post int primary key auto_increment,
id_comunidade int,
Data_post date,
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

create table comentario(
id_comentario int primary key auto_increment,
id_usuario int,
id_post int,
conteudo text,
data_comentario date,

foreign key (id_usuario) references usuario(id_usuario),
foreign key (id_post) references post(id_post)
);

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