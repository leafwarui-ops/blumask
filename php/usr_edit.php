<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Editar Perfil - BluMask</title>
  <link rel="stylesheet" href="../style/index_style.css">
  <link rel="stylesheet" href="../style/edit_style.css">
</head>
<body>
  <div class="page">
    
    <header class="topbar">
      <h1>BluMask</h1>
    </header>

    <!-- MAIN LAYOUT -->
    <!-- Adicionamos "layout-single" para centralizar o painel e remover as colunas laterais -->
    <main class="layout layout-single">
      
      <div class="panel edit-panel">
        
        <!-- PARTE CINZA (TOPO DO PAINEL) -->
        <div class="edit-panel-header">
          <button class="btn-edit-banner" title="Editar Capa">
            <svg viewBox="0 0 24 24"><path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
          </button>
        </div>

        <!-- PARTE AZUL (CORPO DO PAINEL) -->
        <div class="edit-panel-body">
          
          <!-- FOTO DE PERFIL -->
          <div class="edit-avatar-wrapper">
            <img src="https://i.pinimg.com/736x/87/46/76/874676100eb6085a8efb483c7ccfa89b.jpg" alt="Avatar do usuário" class="edit-avatar">
            <button class="btn-edit-avatar" title="Editar Foto">
              <svg viewBox="0 0 24 24"><path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
            </button>
          </div>

          <!-- FORMULÁRIO -->
          <div class="edit-form-grid">
            
            <!-- Coluna da Esquerda -->
            <div class="edit-form-col">
              <div class="form-group">
                <label>Nome de usuario:</label>
                <input type="text">
              </div>
              <div class="form-group">
                <label>Nome de exibição:</label>
                <input type="text">
              </div>
              <div class="form-group">
                <label>Email:</label>
                <input type="email">
              </div>
              <div class="form-group">
                <label>Senha:</label>
                <input type="password" value="********">
              </div>
            </div>

            <!-- Coluna da Direita -->
            <div class="edit-form-col">
              <div class="form-group h-100">
                <label>Descrição:</label>
                <textarea></textarea>
              </div>
            </div>

          </div>

          <!-- BOTÃO VOLTAR -->
          <div class="edit-actions">
            <button onclick="window.location.href='../index.php'" class="btn-voltar">Voltar</button>
          </div>

          <div class="edit-actions">
            <button class="btn_salvar">Salvar</button>
          </div>

        </div>
      </div>

    </main>

    <!-- FOOTER -->
    <footer class="bottombar">
      <strong>Blumask</strong>
      <svg viewBox="0 0 24 24"><path fill="currentColor" d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm-2-12h4v2h-4zm0 4h4v2h-4z"/></svg>
    </footer>

  </div>
</body>
</html>

<?php
session_start();
include "bd.php";


?>