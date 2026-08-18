create database bd_blumask;
use bd_blumask;

create table usuario(
id_usuario int primary key auto_increment,
email varchar(100),
nome_de_exibicao varchar(100) unique,
senha varchar(255),
nome_de_usuario varchar(100) unique,
descricao text,
banner varchar(200),
foto_perfil varchar(200)
);

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

INSERT INTO usuario (email, nome_de_exibicao, senha, nome_de_usuario, descricao, banner, foto_perfil) VALUES
('lucas.silva@email.com', 'Lucas Silva', 'hash_senha_123', 'lucassilva', 'Entusiasta de tecnologia e games.', 'banners/banner_lucas.jpg', 'perfis/lucas.jpg'),
('mariana.costa@email.com', 'Mariana Costa', 'hash_senha_456', 'maricosta', 'Amante de fotografia e viagens pelo mundo.', 'banners/banner_mari.jpg', 'perfis/mari.jpg'),
('carlos.oliveira@email.com', 'Carlos Dev', 'hash_senha_789', 'carlos_dev', 'Desenvolvedor backend e fã de código aberto.', 'banners/banner_carlos.jpg', 'perfis/carlos.jpg'),
('beatriz.lima@email.com', 'Bia Lima', 'hash_senha_abc', 'bialima', 'Designer UX/UI apaixonada por interfaces limpas.', 'banners/banner_bia.jpg', 'perfis/bia.jpg'),
('rodrigo.santos@email.com', 'Rodrigo Santos', 'hash_senha_def', 'rodrigosantos', 'Gamer casual e leitor compulsivo.', 'banners/banner_rodrigo.jpg', 'perfis/rodrigo.jpg'),
('camila.almeida@email.com', 'Cami Almeida', 'hash_senha_ghi', 'camialmeida', 'Produtora de conteúdo sobre cultura pop.', 'banners/banner_cami.jpg', 'perfis/cami.jpg'),
('felipe.rocha@email.com', 'Felipe Rocha', 'hash_senha_jkl', 'felis_rocha', 'Estudante de ciência de dados e IA.', 'banners/banner_felipe.jpg', 'perfis/felipe.jpg'),
('larissa.mendes@email.com', 'Lari Mendes', 'hash_senha_mno', 'larimendes', 'Café, música indie e desenvolvimento web.', 'banners/banner_lari.jpg', 'perfis/lari.jpg'),
('gabriel.ferreira@email.com', 'Gabi Ferreira', 'hash_senha_pqr', 'gabi_ferreira', 'Streamer de eSports e amante de hardware.', 'banners/banner_gabi.jpg', 'perfis/gabi.jpg'),
('juliana.pereira@email.com', 'Juju Pereira', 'hash_senha_stu', 'jujupereira', 'Ilustradora digital e criadora de personagens.', 'banners/banner_juju.jpg', 'perfis/juju.jpg');