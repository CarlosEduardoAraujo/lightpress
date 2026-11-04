<?php
declare(strict_types=1);

namespace LightPress\Core;

/**
 * Matriz de Controle de Acesso Baseado em Papéis (RBAC)
 * 
 * Centraliza decisões de autorização com política estrita de "Negação por Padrão" (Deny by Default).
 * Protege contra acessos horizontais (autor acessando outro autor) e verticais (autor publicando).
 */
class Authorization
{
    public const ROLE_AUTHOR = 'author';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_ADMIN = 'admin';

    // Ações editoriais e administrativas
    public const ACTION_CREATE_POST = 'create_post';
    public const ACTION_EDIT_POST = 'edit_post';
    public const ACTION_SUBMIT_REVIEW = 'submit_review';
    public const ACTION_PUBLISH_POST = 'publish_post';
    public const ACTION_CHANGE_AUTHOR = 'change_author';
    public const ACTION_SET_FEATURED = 'set_featured';
    public const ACTION_MANAGE_CATEGORIES = 'manage_categories';
    public const ACTION_MANAGE_USERS = 'manage_users';
    public const ACTION_DELETE_POST = 'delete_post';

    /**
     * Avalia se um usuário autenticado possui permissão para executar uma ação em determinado contexto.
     *
     * @param array{id: int|string, role: string, is_active: int|string} $user
     * @param string $action
     * @param array{id?: int|string, user_id?: int|string}|null $post
     * @return bool
     */
    public static function can(array $user, string $action, ?array $post = null): bool
    {
        // Contas inativas nunca possuem qualquer permissão
        if (empty($user['is_active'])) {
            return false;
        }

        $role = $user['role'] ?? '';
        $userId = (int)($user['id'] ?? 0);

        // Administrador possui acesso total irrestrito (exceto se inativo)
        if ($role === self::ROLE_ADMIN) {
            return true;
        }

        // Editor
        if ($role === self::ROLE_EDITOR) {
            return match ($action) {
                self::ACTION_CREATE_POST,
                self::ACTION_EDIT_POST,
                self::ACTION_SUBMIT_REVIEW,
                self::ACTION_PUBLISH_POST,
                self::ACTION_CHANGE_AUTHOR,
                self::ACTION_SET_FEATURED,
                self::ACTION_MANAGE_CATEGORIES => true,

                // Editores não gerenciam outros usuários nem realizam exclusão definitiva
                self::ACTION_MANAGE_USERS,
                self::ACTION_DELETE_POST => false,

                default => false,
            };
        }

        // Autor
        if ($role === self::ROLE_AUTHOR) {
            return match ($action) {
                self::ACTION_CREATE_POST => true,

                // Autores só podem editar rascunhos ou enviar para revisão artigos de sua própria autoria
                self::ACTION_EDIT_POST,
                self::ACTION_SUBMIT_REVIEW => $post !== null && (int)($post['user_id'] ?? 0) === $userId,

                // Autores nunca publicam, não alteram autoria, nem destacam posts
                self::ACTION_PUBLISH_POST,
                self::ACTION_CHANGE_AUTHOR,
                self::ACTION_SET_FEATURED,
                self::ACTION_MANAGE_CATEGORIES,
                self::ACTION_MANAGE_USERS,
                self::ACTION_DELETE_POST => false,

                default => false,
            };
        }

        // Papel desconhecido -> Negado
        return false;
    }
}
