<?php
// OpenRanch — bottom navigation. Phone-first: fixed to the bottom of the
// viewport where a thumb reaches, hidden on wide screens where the pages carry
// their own top nav. Sits above the home indicator on notched phones.

function or_bottom_nav($active = '') {
  $items = [
    'controls' => ['controls.php', 'Controls', 'M4 12h5l2-5 3 10 2-5h4'],
    'sensors'  => ['index.php?tab=1', 'Sensors',  'M12 4v8l5 3M12 20a8 8 0 1 1 0-16 8 8 0 0 1 0 16z'],
    'graphs'   => ['graphs.php',   'Graphs',   'M4 19V5M4 19h16M8 15l3-5 3 3 4-7'],
    'programs' => ['programs.php', 'Programs', 'M5 5h14v14H5zM5 9h14M9 3v4M15 3v4'],
    'rules'    => ['rules.php',    'Rules',    'M5 12h4l3-7 3 14 3-7h3'],
    'more'     => ['more.php',     'More',     'M5 12h.01M12 12h.01M19 12h.01'],
  ];
  ?>
<style>
  .btmnav { position:fixed; left:0; right:0; bottom:0; z-index:50; display:flex;
            background:rgba(255,255,255,.96); backdrop-filter:blur(8px);
            border-top:1px solid #e8dfc9;
            padding-bottom:env(safe-area-inset-bottom); }
  .btmnav a { flex:1; display:flex; flex-direction:column; align-items:center; gap:3px;
              padding:8px 2px 7px; text-decoration:none; color:#6b6a5a; font-size:10px;
              letter-spacing:.02em; -webkit-tap-highlight-color:transparent; }
  .btmnav a svg { width:22px; height:22px; stroke:currentColor; stroke-width:1.9;
                  fill:none; stroke-linecap:round; stroke-linejoin:round; }
  .btmnav a.on { color:#9a5410; font-weight:700; }
  .btmnav a.on svg { stroke:#d98a2b; }
  /* The install hint is fixed to the bottom too, so lift it clear of the bar
     rather than letting it sit on top of the tab labels. */
  @media (max-width:759px) {
    #installbar { bottom:calc(64px + env(safe-area-inset-bottom)) !important; }
  }
  /* On a wide screen the pages already have a top nav, so this would be noise. */
  @media (min-width:760px) { .btmnav { display:none; } body { padding-bottom:14px !important; } }
</style>
<nav class="btmnav">
  <?php foreach ($items as $key => [$href, $label, $d]): ?>
    <a href="<?= $href ?>"<?= $key === $active ? ' class="on"' : '' ?>>
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $d ?>"/></svg>
      <span><?= $label ?></span>
    </a>
  <?php endforeach; ?>
</nav>
<?php
}
