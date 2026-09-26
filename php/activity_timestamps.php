<?php

function ensure_activity_timestamp_columns($conn) {
    $columns = [
        ['post', 'Data_post'],
        ['comentario', 'data_comentario']
    ];

    foreach ($columns as [$table, $column]) {
        $result = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
        if (!$result) {
            return false;
        }

        $definition = mysqli_fetch_assoc($result);
        if (!$definition) {
            return false;
        }

        if (strtolower($definition['Type']) === 'date'
            && !mysqli_query($conn, "ALTER TABLE `$table` MODIFY `$column` DATETIME NULL")) {
            return false;
        }
    }

    return true;
}