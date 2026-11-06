<?php
declare(strict_types=1);

namespace LightPress\Core;

/**
 * LightPress / SpeedPress - Sistema de Hooks ultraleve (Actions e Filters)
 *
 * Oferece barramento de eventos de altíssimo desempenho, tipado e com zero dependências externas.
 */
final class Hook
{
    /**
     * @var array<string, array<int, list<callable>>>
     */
    private static array $actions = [];

    /**
     * @var array<string, array<int, list<callable>>>
     */
    private static array $filters = [];

    /**
     * Registra uma Action (evento sem retorno).
     *
     * @param string $tag Nome do evento (ex: 'post_published', 'frontend_head')
     * @param callable $callback Função ou método a ser executado
     * @param int $priority Ordem de execução (menor número executa primeiro; padrão: 10)
     */
    public static function addAction(string $tag, callable $callback, int $priority = 10): void
    {
        self::$actions[$tag][$priority][] = $callback;
    }

    /**
     * Dispara uma Action para todos os ouvintes registrados, ordenados por prioridade.
     *
     * @param string $tag Nome do evento
     * @param mixed ...$args Argumentos passados aos callbacks
     */
    public static function doAction(string $tag, mixed ...$args): void
    {
        if (empty(self::$actions[$tag])) {
            return;
        }

        ksort(self::$actions[$tag], SORT_NUMERIC);

        foreach (self::$actions[$tag] as $priorityGroup) {
            foreach ($priorityGroup as $callback) {
                try {
                    $callback(...$args);
                } catch (\Throwable $e) {
                    error_log(sprintf('[LightPress Hook Action Error] Tag "%s": %s in %s:%d', $tag, $e->getMessage(), $e->getFile(), $e->getLine()));
                }
            }
        }
    }

    /**
     * Registra um Filter (modificador ou interceptador de valores encadeados).
     *
     * @param string $tag Nome do filtro
     * @param callable $callback Função que recebe o valor (e args opcionais) e deve retornar o valor transformado
     * @param int $priority Ordem de execução (menor número primeiro; padrão: 10)
     */
    public static function addFilter(string $tag, callable $callback, int $priority = 10): void
    {
        self::$filters[$tag][$priority][] = $callback;
    }

    /**
     * Aplica uma cadeia de filtros sobre um valor inicial.
     *
     * @param string $tag Nome do filtro
     * @param mixed $value Valor a ser filtrado
     * @param mixed ...$args Argumentos contextuais adicionais
     * @return mixed Valor após passar por todos os filtros
     */
    public static function applyFilters(string $tag, mixed $value, mixed ...$args): mixed
    {
        if (empty(self::$filters[$tag])) {
            return $value;
        }

        ksort(self::$filters[$tag], SORT_NUMERIC);

        foreach (self::$filters[$tag] as $priorityGroup) {
            foreach ($priorityGroup as $callback) {
                try {
                    $value = $callback($value, ...$args);
                } catch (\Throwable $e) {
                    error_log(sprintf('[LightPress Hook Filter Error] Tag "%s": %s in %s:%d', $tag, $e->getMessage(), $e->getFile(), $e->getLine()));
                }
            }
        }

        return $value;
    }

    /**
     * Verifica se existem ouvintes para uma action.
     */
    public static function hasAction(string $tag): bool
    {
        return !empty(self::$actions[$tag]);
    }

    /**
     * Verifica se existem ouvintes para um filtro.
     */
    public static function hasFilter(string $tag): bool
    {
        return !empty(self::$filters[$tag]);
    }

    /**
     * Limpa todos os ouvintes de uma tag ou de todas as tags (útil para testes).
     */
    public static function reset(?string $tag = null): void
    {
        if ($tag !== null) {
            unset(self::$actions[$tag], self::$filters[$tag]);
        } else {
            self::$actions = [];
            self::$filters = [];
        }
    }
}
