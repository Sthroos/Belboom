<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$contacts = own_contacts((int) $user['id'], 200);

render_header('Mijn registraties', $user);
?>
<p><a href="/contact-new.php">+ Nieuw contact registreren</a> · Regio: <?= e(region_label($user['region'])) ?></p>
<?php foreach ($contacts as $row): ?>
<article class="card contact">
  <header>
    <strong><?= e(CONTACT_CATEGORIES[$row['category']]) ?></strong>
    · <?= e($row['contact_date']) ?>
    · <?= e((int) $row['call_minutes']) ?> + <?= e((int) $row['extra_minutes']) ?> min
    · <?= e(ENERGY_LABELS[(int) $row['energy']]) ?>
    · <?= e(VALUE_LABELS[(int) $row['value_score']]) ?>
    <?php if ((int) $row['displaced_work'] === 1): ?>· <em>ten koste van ander werk</em><?php endif; ?>
  </header>
  <p class="pre"><?= e($row['content']) ?></p>
  <?php if ($row['impression'] !== ''): ?><p class="pre muted">Indruk: <?= e($row['impression']) ?></p><?php endif; ?>
  <form method="post" action="/contact-delete.php" class="inline">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= e((int) $row['id']) ?>">
    <button type="submit" class="link danger">Verwijderen</button>
  </form>
</article>
<?php endforeach; ?>
<?php if ($contacts === []): ?><p>Je hebt nog geen contacten geregistreerd.</p><?php endif; ?>
<?php
render_footer();
