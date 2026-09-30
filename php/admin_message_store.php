<?php
function ensure_admin_message_schema(mysqli $conn): bool {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $ready = (bool) $conn->query("CREATE TABLE IF NOT EXISTS mensagem_administrativa (
        id_mensagem BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        id_destinatario INT NOT NULL,
        id_remetente INT NULL,
        mensagem TEXT NOT NULL,
        enviada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        fechada_em DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (id_mensagem),
        KEY idx_mensagem_destinatario (id_destinatario, fechada_em, enviada_em),
        CONSTRAINT fk_mensagem_admin_destinatario FOREIGN KEY (id_destinatario)
            REFERENCES usuario(id_usuario) ON DELETE CASCADE,
        CONSTRAINT fk_mensagem_admin_remetente FOREIGN KEY (id_remetente)
            REFERENCES usuario(id_usuario) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    return $ready;
}
?>
