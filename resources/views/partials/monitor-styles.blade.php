<style>
    :root { --chart-h: 220px; }
    html { color-scheme: dark; }
    body {
        background: #0f1419 !important;
        color: #e6edf3 !important;
        --bs-body-color: #e6edf3;
        --bs-secondary-color: #8b949e;
        --bs-heading-color: #f0f6fc;
    }
    .card {
        background: #161b22 !important;
        border-color: #30363d !important;
        color: #e6edf3 !important;
        --bs-card-color: #e6edf3;
    }
    .card-body, .card-header { color: #e6edf3 !important; }
    .card-header {
        background: #21262d !important;
        border-color: #30363d !important;
        color: #8b949e !important;
        font-size: .8rem;
        text-transform: uppercase;
        letter-spacing: .04em;
    }
    .metric-value {
        font-size: 1.75rem;
        font-weight: 600;
        font-variant-numeric: tabular-nums;
        color: #f0f6fc !important;
    }
    .metric-label { color: #8b949e !important; font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; }
    .table {
        color: #e6edf3 !important;
        --bs-table-color: #e6edf3;
        --bs-table-bg: transparent;
        --bs-table-striped-bg: rgba(255, 255, 255, .03);
        --bs-table-hover-bg: rgba(255, 255, 255, .06);
        --bs-table-hover-color: #e6edf3;
    }
    .table > :not(caption) > * > * { color: #e6edf3 !important; background-color: transparent; }
    .table thead th {
        border-color: #30363d !important;
        color: #8b949e !important;
        font-size: .75rem;
    }
    .table td, .table th { border-color: #30363d !important; vertical-align: middle; }
    .table-hover tbody tr:hover > * { --bs-table-color-state: #e6edf3; color: #e6edf3 !important; }
    pre.log-box {
        font-size: .7rem;
        max-height: 320px;
        overflow: auto;
        background: #0d1117 !important;
        color: #c9d1d9 !important;
        border: 1px solid #30363d;
        border-radius: .375rem;
        padding: .75rem;
    }
    #replica-pre { color: #c9d1d9 !important; }
    .pulse-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #3fb950; animation: pulse 2s infinite; }
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .35; } }
    .chart-wrap { height: var(--chart-h); position: relative; }
    .long-query { background: rgba(248, 81, 73, .15) !important; }
    .long-query td { color: #e6edf3 !important; }
    .navbar-brand { font-weight: 600; color: #e6edf3 !important; }
    .navbar { --bs-navbar-color: #e6edf3; }
    .navbar .nav-link { color: #8b949e !important; }
    .navbar .nav-link:hover, .navbar .nav-link:focus { color: #e6edf3 !important; }
    .navbar .nav-link.active { color: #58a6ff !important; font-weight: 600; }
    .text-secondary { color: #8b949e !important; }
    .text-success { color: #3fb950 !important; }
    .badge.text-success { color: #3fb950 !important; }
    #banner-error.alert-danger {
        background: rgba(248, 81, 73, .18) !important;
        border-color: rgba(248, 81, 73, .55) !important;
        color: #ffb4ab !important;
    }
    code {
        color: #79c0ff !important;
        background: rgba(110, 118, 129, .25) !important;
        padding: .1rem .35rem;
        border-radius: .25rem;
    }
    p.text-secondary, small.text-secondary { color: #8b949e !important; }
    .small { color: inherit; }
    td.small { color: #e6edf3 !important; }
    .query-cell { white-space: pre-wrap; word-break: break-word; max-width: 42rem; font-size: .8rem; }
    tr.fresh-row { animation: rowflash .6s ease-out; }
    @keyframes rowflash { from { background: rgba(88, 166, 255, .15); } to { background: transparent; } }

    /* Realtime health strip */
    .health-card {
        border-radius: .5rem;
        padding: 1rem 1.25rem;
        background: linear-gradient(145deg, rgba(33, 38, 45, .94), rgba(13, 17, 23, .98));
        border: 1px solid #30363d;
        transition: border-color .2s ease, box-shadow .2s ease;
    }
    .health-card--good {
        border-color: rgba(63, 185, 80, .5);
        box-shadow: 0 0 0 1px rgba(63, 185, 80, .1) inset, 0 8px 32px rgba(0, 0, 0, .25);
    }
    .health-card--warning {
        border-color: rgba(210, 153, 34, .62);
        box-shadow: 0 0 0 1px rgba(210, 153, 34, .12) inset, 0 8px 32px rgba(0, 0, 0, .28);
    }
    .health-card--critical {
        border-color: rgba(248, 81, 73, .65);
        box-shadow: 0 0 0 1px rgba(248, 81, 73, .14) inset, 0 8px 32px rgba(248, 81, 73, .08);
    }
    .health-dot {
        width: .75rem;
        height: .75rem;
        border-radius: 50%;
        flex-shrink: 0;
        animation: health-dot-pulse 2.4s ease-in-out infinite;
    }
    @keyframes health-dot-pulse {
        0%, 100% { transform: scale(1); opacity: 1; }
        50% { transform: scale(.88); opacity: .55; }
    }
    .health-card--good .health-dot {
        background: #3fb950;
        box-shadow: 0 0 14px rgba(63, 185, 80, .75);
    }
    .health-card--warning .health-dot {
        background: #d29922;
        box-shadow: 0 0 14px rgba(210, 153, 34, .65);
    }
    .health-card--critical .health-dot {
        background: #f85149;
        box-shadow: 0 0 14px rgba(248, 81, 73, .7);
    }
    .health-card__title {
        font-size: 1.05rem;
        font-weight: 600;
        color: #f0f6fc !important;
        line-height: 1.35;
        letter-spacing: .01em;
    }
    .health-toggle-btn {
        color: #58a6ff !important;
        border: 1px solid rgba(88, 166, 255, .35);
        background: rgba(88, 166, 255, .08) !important;
        font-size: .8125rem;
        font-weight: 500;
        padding: .35rem .65rem;
        border-radius: .375rem;
    }
    .health-toggle-btn:hover {
        color: #79c0ff !important;
        background: rgba(88, 166, 255, .14) !important;
        border-color: rgba(88, 166, 255, .45);
    }
    .health-toggle-btn:disabled {
        opacity: .45;
        pointer-events: none;
    }
    .health-toggle-chevron {
        display: inline-block;
        font-size: .65rem;
        line-height: 1;
        transition: transform .2s ease;
    }
    .health-toggle-btn[aria-expanded="true"] .health-toggle-chevron {
        transform: rotate(180deg);
    }
    .health-card__blurb {
        color: #c9d1d9 !important;
        font-size: .925rem;
        line-height: 1.55;
        max-width: 58rem;
    }
    .health-items { border-top: 1px solid rgba(48, 54, 61, .4); padding-top: .25rem; }
    .health-item {
        padding: .65rem 0 .65rem 1rem;
        border-left: 3px solid transparent;
        border-bottom: 1px solid rgba(48, 54, 61, .35);
    }
    .health-item:last-child { border-bottom: 0; }
    .health-item__label {
        display: block;
        font-size: .76rem;
        text-transform: uppercase;
        letter-spacing: .055em;
        margin-bottom: .2rem;
    }
    .health-item__detail {
        display: block;
        font-size: .88rem;
        color: #b1bac4 !important;
        font-weight: 400;
        line-height: 1.45;
        text-transform: none;
        letter-spacing: normal;
        font-variant-numeric: tabular-nums;
    }
    .health-item--good { border-left-color: rgba(63, 185, 80, .75); background: rgba(63, 185, 80, .04); }
    .health-item--warning { border-left-color: rgba(210, 153, 34, .85); background: rgba(210, 153, 34, .07); }
    .health-item--critical { border-left-color: rgba(248, 81, 73, .9); background: rgba(248, 81, 73, .08); }
    .health-item--good .health-item__label { color: #7ee787 !important; }
    .health-item--warning .health-item__label { color: #eac54f !important; }
    .health-item--critical .health-item__label { color: #ff8a8a !important; }

    /* Disk / iostat dashboards */
    .disk-dash-shell { transition: box-shadow .18s ease, border-color .18s ease; }
    .disk-dash-good { box-shadow: inset 0 0 0 1px rgba(63, 185, 80, .18) !important; }
    .disk-dash-warn { box-shadow: inset 0 0 0 1px rgba(210, 153, 34, .22) !important; }
    .disk-dash-critical { box-shadow: inset 0 0 0 1px rgba(248, 81, 73, .25) !important; }
    .disk-dash-unknown { box-shadow: inset 0 0 0 1px rgba(110, 118, 129, .12) !important; }
    .disk-dash-badge.disk-level-good {
        background: rgba(63, 185, 80, .2) !important;
        border: 1px solid rgba(63, 185, 80, .45);
        color: #7ee787 !important;
    }
    .disk-dash-badge.disk-level-warning {
        background: rgba(210, 153, 34, .18) !important;
        border: 1px solid rgba(210, 153, 34, .5);
        color: #eac54f !important;
    }
    .disk-dash-badge.disk-level-critical {
        background: rgba(248, 81, 73, .16) !important;
        border: 1px solid rgba(248, 81, 73, .45);
        color: #ffa28b !important;
    }
    .disk-dash-badge.disk-level-unknown {
        background: rgba(110, 118, 129, .2) !important;
        border: 1px solid rgba(139, 148, 158, .35);
        color: #8b949e !important;
    }
    .disk-level-text.disk-level-good { color: #7ee787 !important; }
    .disk-level-text.disk-level-warning { color: #eac54f !important; }
    .disk-level-text.disk-level-critical { color: #ff8a8a !important; }
    .disk-level-text.disk-level-unknown { color: #8b949e !important; }
    .disk-border-good { border-color: rgba(63, 185, 80, .45) !important; }
    .disk-border-warn { border-color: rgba(210, 153, 34, .5) !important; }
    .disk-border-critical { border-color: rgba(248, 81, 73, .55) !important; }
    .disk-border-muted { border-color: rgba(139, 148, 158, .25) !important; }
    .disk-pill-yes {
        background: rgba(88, 166, 255, .14) !important;
        border: 1px solid rgba(88, 166, 255, .38);
        color: #91cbff !important;
        font-size: .62rem;
    }
    .disk-pill-no {
        background: rgba(110, 118, 129, .12) !important;
        border: 1px solid rgba(139, 148, 158, .22);
        color: #6e7681 !important;
        font-size: .62rem;
    }
</style>
