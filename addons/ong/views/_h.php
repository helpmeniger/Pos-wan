<?php
/** Aides d'affichage du module ONG (champs de formulaire, onglets, badges). */
if (!function_exists('ong_field')) {
    function ong_field(string $name, string $label, mixed $value = '', string $type = 'text', string $attrs = '', string $col = 'col-md-3'): string
    {
        $v = $type === 'number' && $value !== '' && $value !== null ? (string) (float) $value : (string) $value;
        return '<div class="' . $col . '"><label class="form-label small mb-0">' . e(t($label)) . '</label><input type="' . $type . '" name="' . e($name) . '" value="' . e($v) . '" class="form-control form-control-sm" ' . $attrs . '></div>';
    }
    /** @param array<string|int, string> $options */
    function ong_select(string $name, string $label, array $options, mixed $value = '', string $attrs = '', string $col = 'col-md-3', bool $translate = true): string
    {
        $h = '<div class="' . $col . '"><label class="form-label small mb-0">' . e(t($label)) . '</label><select name="' . e($name) . '" class="form-select form-select-sm" ' . $attrs . '>';
        foreach ($options as $k => $l) {
            $h .= '<option value="' . e($k) . '"' . ((string) $k === (string) $value ? ' selected' : '') . '>' . e($translate ? t($l) : $l) . '</option>';
        }
        return $h . '</select></div>';
    }
    /** Liste « Convention — ligne budgétaire » : la convention est déduite de la ligne choisie. */
    function ong_lignes(array $lignes, mixed $value = '', string $col = 'col-md-4'): string
    {
        $h = '<div class="' . $col . '"><label class="form-label small mb-0">' . e(t('Convention et ligne budgétaire')) . '</label><select name="ligne_id" class="form-select form-select-sm" required><option value="">' . e(t('Choisir…')) . '</option>';
        $g = null;
        foreach ($lignes as $l) {
            if ($g !== $l['number']) {
                $h .= ($g !== null ? '</optgroup>' : '') . '<optgroup label="' . e($l['number']) . '">';
                $g = $l['number'];
            }
            $h .= '<option value="' . (int) $l['id'] . '"' . ((int) $l['id'] === (int) $value ? ' selected' : '') . '>' . e($l['code'] . ' — ' . $l['label']) . '</option>';
        }
        return $h . ($g !== null ? '</optgroup>' : '') . '</select></div>';
    }
    function ong_badge(string $label, string $color): string
    {
        return '<span class="badge text-bg-' . e($color) . '">' . e(t($label)) . '</span>';
    }
    function ong_bar(float $rate): string
    {
        $c = $rate > 100 ? 'bg-danger' : ($rate >= 85 ? 'bg-warning' : 'bg-success');
        return '<div class="progress" style="height:8px;min-width:70px" title="' . e((string) $rate) . ' %"><div class="progress-bar ' . $c . '" style="width:' . min(100, max(0, $rate)) . '%"></div></div>';
    }
    /** @param array<int, array{0:string,1:string,2:bool}> $tabs [libellé, adresse, actif] */
    function ong_tabs(array $tabs): string
    {
        $h = '<ul class="nav nav-tabs mb-3">';
        foreach ($tabs as [$l, $u, $a]) {
            $h .= '<li class="nav-item"><a class="nav-link' . ($a ? ' active' : '') . '" href="' . e(url($u)) . '">' . e(t($l)) . '</a></li>';
        }
        return $h . '</ul>';
    }
    function ong_opts(array $rows, string|\Closure $label, string $first = "—"): array
    {
        $o = ['' => $first];
        foreach ($rows as $r) {
            $o[$r["id"]] = $label instanceof \Closure ? $label($r) : (string) $r[$label];
        }
        return $o;
    }
}
