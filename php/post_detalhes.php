<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";

if (!isset($_SESSION['usuario'])) {
    header("Location: ../index.php");
    exit;
}

$id_post = intval($_GET['id_post'] ?? 0);

if ($id_post <= 0) {
    header("Location: ../index.php");
    exit;
}

global $conn;
$id_usuario = intval($_SESSION['usuario']['id_usuario']);

$sql_post = "SELECT p.*, u.nome_de_exibicao, u.nome_de_usuario, u.foto_perfil, c.nome AS nome_comunidade, c.id_comunidade,
             (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post) as total_curtidas,
             (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post AND id_usuario = $id_usuario) as curtiu,
             (SELECT COUNT(*) FROM comentario WHERE id_post = p.id_post) as total_comentarios
             FROM post p
             JOIN usuario u ON p.id_usuario = u.id_usuario
             JOIN comunidade c ON c.id_comunidade = p.id_comunidade
             WHERE p.id_post = $id_post LIMIT 1";

$resultado_post = mysqli_query($conn, $sql_post);

if (!$resultado_post || mysqli_num_rows($resultado_post) === 0) {
    header("Location: ../index.php");
    exit;
}

$post = mysqli_fetch_assoc($resultado_post);

$sql_comentarios = "SELECT c.*, u.nome_de_exibicao, u.nome_de_usuario, u.foto_perfil
                    FROM comentario c
                    JOIN usuario u ON c.id_usuario = u.id_usuario
                    WHERE c.id_post = $id_post
                    ORDER BY c.data_comentario ASC, c.id_comentario ASC";

$resultado_comentarios = mysqli_query($conn, $sql_comentarios);
$comentarios = [];

if ($resultado_comentarios) {
    while ($comentario = mysqli_fetch_assoc($resultado_comentarios)) {
        $comentarios[] = $comentario;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Post - BluMask</title>
    <link rel="icon" type="image/webp" href="../style/blumaskWhiteLogo.webp">
    <link rel="stylesheet" href="../style/index_style.css">
    <link rel="stylesheet" href="../style/comunidade_style.css?v=<?= time() ?>">
</head>
<body>
    <div class="page">
        <header class="topbar" style="display: flex; justify-content: space-between; align-items: center; padding: 0 20px; min-height: 60px; background: white; border-bottom: 1px solid #ddd; position: sticky; top: 0; z-index: 100;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <a href="../index.php" style="text-decoration: none; display: flex; align-items: center; gap: 8px; color: #333;">
                    <img src="../style/blumaskBlueLogo.webp" alt="BluMask Logo" style="height: 36px; width: auto; object-fit: contain;">
                    <h1 style="margin: 0; font-size: 20px;">BluMask</h1>
                </a>
            </div>
            <a href="comunidade.php?id=<?= intval($post['id_comunidade']) ?>" style="text-decoration: none; color: #2563eb; font-weight: bold; font-size: 14px;">Voltar para a comunidade</a>
        </header>

        <main class="post-detail-page">
            <div class="post-detail-card">
                <div class="post-detail-header">
                    <div class="post-avatar">
                        <img src="<?= !empty($post['foto_perfil']) ? htmlspecialchars($post['foto_perfil'], ENT_QUOTES, 'UTF-8') : 'https://ui-avatars.com/api/?name=' . urlencode(htmlspecialchars($post['nome_de_exibicao'], ENT_QUOTES, 'UTF-8')) . '&background=random'; ?>" alt="Avatar">
                    </div>
                    <div class="post-header-info">
                        <div class="post-user-info">
                            <h4><?= htmlspecialchars($post['nome_de_exibicao'], ENT_QUOTES, 'UTF-8') ?></h4>
                            <p>@<?= htmlspecialchars($post['nome_de_usuario'], ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                        <div class="post-date"><?= date('d/m/Y', strtotime($post['Data_post'])) ?></div>
                    </div>
                </div>

                <div class="post-detail-community"><?= htmlspecialchars($post['nome_comunidade'], ENT_QUOTES, 'UTF-8') ?></div>
                <h2 class="post-detail-title"><?= htmlspecialchars($post['assunto'], ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="post-content"><?= htmlspecialchars($post['conteudo'], ENT_QUOTES, 'UTF-8') ?></div>

                <div class="post-actions post-detail-actions">
                    <span class="post-action" onclick="curtirPost(<?= $post['id_post'] ?>, this)">
                        <span><?php echo intval($post['curtiu']) === 1 ? '❤️' : '🤍'; ?></span>
                        <span><?= intval($post['total_curtidas']) ?></span>
                    </span>
                    <span class="post-action" aria-label="Comentários">
                        <span>💬</span>
                        <span><?= intval($post['total_comentarios']) ?></span>
                    </span>
                </div>
            </div>

            <div class="comments-list">
                <h3>Comentários</h3>

                <div class="comment-detail-box">
                    <h3>Adicionar comentário</h3>
                    <form id="formComentarioDetalhe" class="form-comentario">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="id_post" value="<?= $post['id_post'] ?>">
                        <textarea name="conteudo" placeholder="Digite seu comentário..." required></textarea>
                        <button type="submit">Enviar comentário</button>
                    </form>
                </div>

                <?php if (count($comentarios) > 0): ?>
                    <?php foreach ($comentarios as $comentario): ?>
                        <div class="comment-item">
                            <div class="comment-avatar">
                                <img src="<?= !empty($comentario['foto_perfil']) ? htmlspecialchars($comentario['foto_perfil'], ENT_QUOTES, 'UTF-8') : 'https://ui-avatars.com/api/?name=' . urlencode(htmlspecialchars($comentario['nome_de_exibicao'], ENT_QUOTES, 'UTF-8')) . '&background=random'; ?>" alt="Avatar do usuário">
                            </div>
                            <div class="comment-body">
                                <div class="comment-meta">
                                    <strong><?= htmlspecialchars($comentario['nome_de_exibicao'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span>@<?= htmlspecialchars($comentario['nome_de_usuario'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <time><?= date('d/m/Y', strtotime($comentario['data_comentario'])) ?></time>
                                </div>
                                <p><?= htmlspecialchars($comentario['conteudo'], ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="comments-empty">Nenhum comentário ainda. Seja o primeiro a responder este post.</div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        function curtirPost(idPost) {
            const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';

            fetch('curtir_post.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `csrf_token=${encodeURIComponent(csrfToken)}&id_post=${idPost}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.sucesso) {
                    location.reload();
                }
            })
            .catch(error => console.error('Erro:', error));
        }

        const formComentarioDetalhe = document.getElementById('formComentarioDetalhe');
        if (formComentarioDetalhe) {
            formComentarioDetalhe.addEventListener('submit', function(e) {
                e.preventDefault();

                const conteudo = this.querySelector('textarea[name="conteudo"]').value.trim();
                if (conteudo.length < 2) {
                    return;
                }

                const formData = new FormData(this);
                fetch('criar_comentario.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.sucesso) {
                        location.reload();
                    } else {
                        alert(data.mensagem || 'Não foi possível enviar o comentário.');
                    }
                })
                .catch(error => {
                    console.error('Erro ao comentar:', error);
                    alert('Erro ao comentar. Tente novamente.');
                });
            });
        }
    </script>
</body>
</html>
