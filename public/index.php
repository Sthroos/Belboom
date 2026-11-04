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
    <strong><?= e(category_label($row)) ?></strong> · <?= e(contact_summary($row)) ?>
    <?php if ($row['updated_at'] !== null): ?><span class="sub-inline">(bewerkt)</span><?php endif; ?>
  </header>
  <?php if ($row['content'] !== ''): ?><p class="pre"><?= e($row['content']) ?></p><?php endif; ?>
  <?php if ($row['impression'] !== ''): ?><p class="pre muted">Indruk: <?= e($row['impression']) ?></p><?php endif; ?>
  <div class="row-actions">
    <a href="/contact-edit.php?id=<?= e((int) $row['id']) ?>">Bewerken</a>
    <form method="post" action="/contact-delete.php" class="inline">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= e((int) $row['id']) ?>">
      <button type="submit" class="link danger">Verwijderen</button>
    </form>
  </div>
</article>
<?php endforeach; ?>
<?php if ($contacts === []): ?><p>Je hebt nog geen contacten geregistreerd.</p><?php endif; ?>
<?php
render_footer();
