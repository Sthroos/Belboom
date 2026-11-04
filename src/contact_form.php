<?php
declare(strict_types=1);

/** Registration form shared by contact-new.php and contact-edit.php. */
function render_contact_form(array $data, string $action, string $submitLabel, DateTimeImmutable $today, ?int $contactId = null): void
{
    ?>
<form method="post" action="<?= e($action) ?>" class="card">
  <?= csrf_field() ?>
  <?php if ($contactId !== null): ?><input type="hidden" name="id" value="<?= e($contactId) ?>"><?php endif; ?>
  <div class="grid">
    <label>Datum contact
      <input type="date" name="contact_date" value="<?= e($data['contact_date']) ?>" max="<?= e($today->format('Y-m-d')) ?>" required>
    </label>
    <div>
      <label>Soort contact
        <select name="category" required><option value="">Kies…</option><?php render_options(CONTACT_CATEGORIES, $data['category']); ?></select>
      </label>
      <label class="other-field other-category">Omschrijving <span class="sub-inline">(bij overig, max. <?= CATEGORY_OTHER_MAX ?> tekens)</span>
        <input name="category_other" maxlength="<?= CATEGORY_OTHER_MAX ?>" size="30" class="narrow-input" value="<?= e($data['category_other'] ?? '') ?>">
      </label>
    </div>
    <label>Uitkomst belpoging
      <select name="outcome" required><option value="">Kies…</option><?php render_options(OUTCOMES, $data['outcome']); ?></select>
    </label>
    <div class="cancel-block">
      <label>Opzegreden
        <select name="cancel_reason"><option value="">n.v.t.</option><?php render_options(CANCEL_REASONS, $data['cancel_reason']); ?></select>
      </label>
      <label class="other-field other-reason">Toelichting <span class="sub-inline">(bij overig, max. <?= CANCEL_REASON_OTHER_MAX ?> tekens)</span>
        <input name="cancel_reason_other" maxlength="<?= CANCEL_REASON_OTHER_MAX ?>" value="<?= e($data['cancel_reason_other'] ?? '') ?>">
      </label>
    </div>
    <label>Gesprekstijd (minuten, 0 bij niet bereikt)
      <input type="number" name="call_minutes" min="0" max="240" value="<?= e($data['call_minutes']) ?>" required>
    </label>
    <label>Overige tijd: voorbereiden, terugbellen, verslag (minuten)
      <input type="number" name="extra_minutes" min="0" max="240" value="<?= e($data['extra_minutes']) ?>" required>
    </label>
    <label>Energie-impact <span class="sub-inline">(bij gevoerd gesprek)</span>
      <select name="energy"><option value="">n.v.t.</option><?php render_options(ENERGY_LABELS, $data['energy']); ?></select>
    </label>
    <label>Meerwaarde van dit contact <span class="sub-inline">(bij gevoerd gesprek)</span>
      <select name="value_score"><option value="">n.v.t.</option><?php render_options(VALUE_LABELS, $data['value_score']); ?></select>
    </label>
    <label>Vervolgactie nodig door
      <select name="follow_up" required><option value="">Kies…</option><?php render_options(FOLLOW_UPS, $data['follow_up']); ?></select>
    </label>
    <label>Ging dit ten koste van ander ambassadeurswerk?
      <select name="displaced_work" required><option value="">Kies…</option><?php render_options([0 => 'Nee', 1 => 'Ja'], $data['displaced_work']); ?></select>
    </label>
  </div>
  <label>Inhoud van het contact <span class="sub-inline">(verplicht bij gevoerd gesprek; zonder namen of herleidbare gegevens van het lid)</span>
    <textarea name="content" rows="5" maxlength="<?= CONTENT_MAX ?>"><?= e($data['content']) ?></textarea>
  </label>
  <label>Kwalitatieve indruk <span class="sub-inline">(optioneel; kan geanonimiseerd in het evaluatierapport komen)</span>
    <textarea name="impression" rows="3" maxlength="<?= IMPRESSION_MAX ?>"><?= e($data['impression']) ?></textarea>
  </label>
  <button type="submit"><?= e($submitLabel) ?></button>
</form>
<?php
}

function category_label(array $row): string
{
    return CONTACT_CATEGORIES[$row['category']] . ($row['category_other'] === null ? '' : ': ' . $row['category_other']);
}

/** One-line summary of a registration for lists. */
function contact_summary(array $row): string
{
    $parts = [
        $row['contact_date'],
        OUTCOMES[$row['outcome']],
        (int) $row['call_minutes'] . ' + ' . (int) $row['extra_minutes'] . ' min',
    ];
    if ($row['cancel_reason'] !== null) {
        $parts[] = 'reden: ' . CANCEL_REASONS[$row['cancel_reason']]
            . ($row['cancel_reason_other'] === null ? '' : ' (' . $row['cancel_reason_other'] . ')');
    }
    if ($row['energy'] !== null) {
        $parts[] = ENERGY_LABELS[(int) $row['energy']];
    }
    if ($row['value_score'] !== null) {
        $parts[] = VALUE_LABELS[(int) $row['value_score']];
    }
    if ($row['follow_up'] !== null && $row['follow_up'] !== 'geen') {
        $parts[] = 'vervolg: ' . FOLLOW_UPS[$row['follow_up']];
    }
    if ((int) $row['displaced_work'] === 1) {
        $parts[] = 'ten koste van ander werk';
    }
    return implode(' · ', $parts);
}
