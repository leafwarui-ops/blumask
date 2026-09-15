<?php
/**
 * ARQUIVO: bd.php
 * DESCRIÇÃO: Arquivo de configuração de conexão com o banco de dados MySQL
 * Este arquivo é incluído em praticamente todos os arquivos PHP do projeto para estabelecer a conexão com a base de dados
 */

// Define o host (servidor) onde o MySQL está rodando - "localhost" significa que é na mesma máquina
$host = "localhost";

// Define o usuário do MySQL que será usado para autenticação (padrão de instalação é "root")
$user = "root";

// Define a senha do usuário MySQL (vazio = sem senha configurada no ambiente de desenvolvimento)
$pass = "";

// Define o nome do banco de dados a ser conectado (criado especificamente para a aplicação BluMask)
$db = "bd_blumask";

// Cria uma nova conexão MySQLi orientada a objetos com os parâmetros definidos acima
$conn = new mysqli($host, $user, $pass, $db);

// Verifica se houve erro na conexão (ex: banco não existe, credenciais incorretas, servidor desligado)
if ($conn->connect_error) {
    // Se houver erro, exibe mensagem de falha e interrompe a execução do script
    die("Conexão falhou: " . $conn->connect_error);
}
// Se chegou aqui, a conexão foi estabelecida com sucesso e $conn pode ser usado nos demais arquivos
?>