{{-- Оформление отчётов «Аналитики сайта»: только нейтральные полупрозрачные
     цвета — одинаково читается в светлой и тёмной теме админки. --}}
<style>
    .la-grid { display: grid; gap: 16px; }
    .la-cols-2 { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); align-items: start; }
    @media (max-width: 640px) { .la-cols-2 { grid-template-columns: 1fr; } }
    .la-kpis { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
    .la-kpi { display: block; padding: 12px 14px; border-radius: 10px; box-shadow: inset 0 0 0 1px rgba(127, 127, 127, .2); }
    a.la-kpi:hover { box-shadow: inset 0 0 0 1px #51a940; }
    .la-kpi b { display: block; font-size: 1.5rem; line-height: 1.2; font-weight: 600; white-space: nowrap; }
    .la-kpi span { font-size: .8rem; opacity: .7; }
    .la-periods { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
    .la-periods button { padding: 5px 12px; border-radius: 999px; font-size: .85rem; box-shadow: inset 0 0 0 1px rgba(127, 127, 127, .3); }
    .la-periods button.is-on { background: #51a940; color: #fff; box-shadow: none; }
    .la-periods small { margin-left: 6px; opacity: .65; }
    .la-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
    .la-table th { text-align: left; font-weight: 500; opacity: .65; padding: 6px 8px; border-bottom: 1px solid rgba(127, 127, 127, .25); white-space: nowrap; }
    .la-table td { padding: 6px 8px; border-bottom: 1px solid rgba(127, 127, 127, .12); vertical-align: top; }
    .la-table .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .la-table tr.is-brand td:first-child { font-weight: 600; color: #51a940; }
    .la-bar { position: relative; }
    .la-bar i { position: absolute; left: 0; top: 2px; bottom: 2px; background: rgba(81, 169, 64, .16); border-radius: 4px; }
    .la-bar span { position: relative; }
    .la-chart { display: flex; align-items: flex-end; gap: 2px; height: 160px; padding-top: 8px; }
    .la-chart div { flex: 1 1 0; min-width: 1px; display: flex; flex-direction: column; justify-content: flex-end; height: 100%; }
    .la-chart div i { display: block; background: #51a940; border-radius: 2px 2px 0 0; min-height: 1px; }
    .la-chart div i.is-empty { background: transparent; border-top: 1px dashed rgba(127, 127, 127, .4); }
    .la-axis { display: flex; justify-content: space-between; font-size: .75rem; opacity: .6; margin-top: 4px; }
    .la-legend { display: flex; gap: 16px; font-size: .8rem; opacity: .8; margin-top: 6px; flex-wrap: wrap; }
    .la-legend i { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 6px; vertical-align: -1px; }
    .la-note { font-size: .82rem; opacity: .7; line-height: 1.55; }
    .la-error { padding: 10px 14px; border-radius: 8px; color: #dc2626; box-shadow: inset 0 0 0 1px rgba(220, 38, 38, .4); line-height: 1.5; }
    .la-callout { padding: 14px 16px; border-radius: 10px; box-shadow: inset 0 0 0 1px rgba(81, 169, 64, .5); line-height: 1.65; }
    .la-callout a, .la-link { text-decoration: underline; color: #51a940; }
    .la-muted { opacity: .6; }
    .la-scroll { overflow-x: auto; }
</style>
