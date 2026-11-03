<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$today = new DateTimeImmutable('today');
$errors = [];
$data = ['contact_date' => $today->format('Y-m-d'), 'category' => null, 'call_minutes' => '', 'extra_minutes' => '0',
    'energy' => null, 'value_score' => null, 'displaced_work' => null, 'content' => '', 'impression' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_valid_post();
    [$data, $errors] = validate_contact($_POST, $today);
    if ($errors === []) {
        insert_contact((int) $user['id'], $data);
        log_event('info', 'contact_created', ['user_id' => (int) $user['id'], 'category' => $data['category']]);
        flash('ok', 'Contact geregistreerd.');
        redirect('/');
    }
}

render_header('Contact registreren', $user);
render_errors($errors);
?>
<form method="post" action="/contact-new.php" class="card">
  <?= csrf_field() ?>
  <div class="grid">
    <label>Datum contact
      <input type="date" name="contact_date" value="<?= e($data['contact_date']) ?>" max="<?= e($today->format('Y-m-d')) ?>" required>
    </label>
    <label>Soort contact / reden
      <select name="category" required><option value="">Kies…</option><?php render_options(CONTACT_CATEGORIES, $data['category']); ?></select>
    </label>
    <label>Gesprekstijd (minuten)
      <input type="number" name="call_minutes" min="1" max="240" value="<?= e($data['call_minutes']) ?>" required>
    </label>
    <label>Overige tijd: voorbereiden, terugbellen, verslag (minuten)
      <input type="number" name="extra_minutes" min="0" max="240" value="<?= e($data['extra_minutes']) ?>" required>
    </label>
    <label>Energie-impact
      <select name="energy" required><option value="">Kies…</option><?php render_options(ENERGY_LABELS, $data['energy']); ?></select>
    </label>
    <label>Meerwaarde van dit contact
      <select name="value_score" required><option value="">Kies…</option><?php render_options(VALUE_LABELS, $data['value_score']); ?></select>
    </label>
    <label>Ging dit ten koste van ander ambassadeurswerk?
      <select name="displaced_work" required><option value="">Kies…</option><?php render_options([0 => 'Nee', 1 => 'Ja'], $data['displaced_work']); ?></select>
    </label>
  </div>
  <label>Inhoud van het contact (zonder namen of herleidbare gegevens van het lid)
    <textarea name="content" rows="5" maxlength="<?= CONTENT_MAX ?>" required><?= e($data['content']) ?></textarea>
  </label>
  <label>Kwalitatieve indruk (optioneel)
    <textarea name="impression" rows="3" maxlength="<?= IMPRESSION_MAX ?>"><?= e($data['impression']) ?></textarea>
  </label>
  <button type="submit">Opslaan</button>
</form>
<?php
render_footer();
