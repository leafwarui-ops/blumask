<?php

function ensure_profile_pin_tables($conn) {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS perfil_post_fixado (
        id_usuario INT NOT NULL,
        id_post INT NOT NULL,
        data_fixacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_usuario, id_post),
        INDEX idx_perfil_post_fixado_post (id_post)
    ) ENGINE=InnoDB");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS perfil_comentario_fixado (
        id_usuario INT NOT NULL,
        id_comentario INT NOT NULL,
        data_fixacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_usuario, id_comentario),
        INDEX idx_perfil_comentario_fixado_comentario (id_comentario)
    ) ENGINE=InnoDB");

    $post_column = mysqli_query($conn, "SHOW COLUMNS FROM usuario LIKE 'id_post_fixado'");
    if ($post_column && mysqli_num_rows($post_column) > 0) {
        mysqli_query($conn, "INSERT IGNORE INTO perfil_post_fixado (id_usuario, id_post)
            SELECT id_usuario, id_post_fixado FROM usuario
            WHERE id_post_fixado IS NOT NULL AND id_post_fixado > 0");
        mysqli_query($conn, "UPDATE usuario SET id_post_fixado = NULL WHERE id_post_fixado IS NOT NULL");
    }

    $comment_column = mysqli_query($conn, "SHOW COLUMNS FROM usuario LIKE 'id_comentario_fixado'");
    if ($comment_column && mysqli_num_rows($comment_column) > 0) {
        mysqli_query($conn, "INSERT IGNORE INTO perfil_comentario_fixado (id_usuario, id_comentario)
            SELECT id_usuario, id_comentario_fixado FROM usuario
            WHERE id_comentario_fixado IS NOT NULL AND id_comentario_fixado > 0");
        mysqli_query($conn, "UPDATE usuario SET id_comentario_fixado = NULL WHERE id_comentario_fixado IS NOT NULL");
    }
}
