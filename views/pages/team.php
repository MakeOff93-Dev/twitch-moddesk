<?php $roleLabels = ['owner' => 'Owner', 'admin' => 'Admin', 'moderator' => 'Moderator', 'viewer' => 'Nur Lesen']; ?>
<div class="page-intro"><div><p>Lokale Panel-Zugänge und ihre Rechte verwalten. Das ist unabhängig von Twitch-Rollen.</p></div><a class="button button-primary" href="#team-form">+ Zugang anlegen</a></div>

<section class="card form-card" id="team-form">
    <div class="section-head"><div><p class="eyebrow"><?= $editMember ? 'BEARBEITEN' : 'NEUER ZUGANG' ?></p><h3><?= $editMember ? e($editMember['display_name']) : 'Teammitglied hinzufügen' ?></h3></div><?php if ($editMember): ?><a href="<?= e(url('team')) ?>">Abbrechen ×</a><?php endif; ?></div>
    <form method="post" class="form-grid">
        <?= csrf_field() ?><input type="hidden" name="action" value="team-save"><input type="hidden" name="return_page" value="team"><input type="hidden" name="id" value="<?= e($editMember['id'] ?? 0) ?>">
        <label><span>Benutzername *</span><input type="text" name="username" required minlength="3" maxlength="50" pattern="[a-zA-Z0-9_.-]+" value="<?= e($editMember['username'] ?? '') ?>"></label>
        <label><span>Anzeigename *</span><input type="text" name="display_name" required maxlength="100" value="<?= e($editMember['display_name'] ?? '') ?>"></label>
        <label><span>E-Mail</span><input type="email" name="email" maxlength="190" value="<?= e($editMember['email'] ?? '') ?>"></label>
        <label><span>Rolle</span><select name="role"><?php foreach ($roleLabels as $value => $label): ?><?php if ($value === 'owner' && auth()->user()['role'] !== 'owner') continue; ?><option value="<?= e($value) ?>" <?= ($editMember['role'] ?? 'viewer') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label><span>Passwort <?= $editMember ? '(leer = unverändert)' : '*' ?></span><input type="password" name="password" <?= $editMember ? '' : 'required' ?> minlength="12" autocomplete="new-password"></label>
        <label class="check-label field-bottom"><input type="checkbox" name="active" value="1" <?= !isset($editMember['active']) || $editMember['active'] ? 'checked' : '' ?>><span>Zugang aktiv</span></label>
        <div class="span-2 form-actions"><button class="button button-primary" type="submit"><?= $editMember ? 'Zugang aktualisieren' : 'Zugang erstellen' ?></button></div>
    </form>
</section>

<section class="card table-card">
    <div class="section-head"><div><p class="eyebrow">PANEL-ZUGÄNGE</p><h3><?= count($members) ?> Teammitglieder</h3></div></div>
    <div class="responsive-table"><table><thead><tr><th>Teammitglied</th><th>Rolle</th><th>Status</th><th>Letzter Login</th><th>Erstellt</th><th></th></tr></thead><tbody><?php foreach ($members as $member): ?><tr>
        <td data-label="Teammitglied"><div class="table-user"><span class="avatar avatar-small avatar-text"><?= e(mb_strtoupper(mb_substr((string) $member['display_name'], 0, 1))) ?></span><span><strong><?= e($member['display_name']) ?></strong><small>@<?= e($member['username']) ?><?= $member['email'] ? ' · ' . e($member['email']) : '' ?></small></span></div></td>
        <td data-label="Rolle"><span class="badge role-<?= e($member['role']) ?>"><?= e($roleLabels[$member['role']] ?? $member['role']) ?></span></td>
        <td data-label="Status"><span class="status-label <?= $member['active'] ? 'active' : 'inactive' ?>"><i></i><?= $member['active'] ? 'Aktiv' : 'Deaktiviert' ?></span></td>
        <td data-label="Letzter Login"><?= e(utc_to_local($member['last_login_at'])) ?></td><td data-label="Erstellt"><?= e(utc_to_local($member['created_at'])) ?></td><td><a class="icon-button" href="<?= e(url('team', ['edit' => $member['id']]) . '#team-form') ?>">✎</a></td>
    </tr><?php endforeach; ?></tbody></table></div>
</section>

<section class="permission-grid">
    <article class="card"><span class="role-dot owner"></span><h3>Owner</h3><p>Vollzugriff, Twitch-Verbindung, Teamverwaltung und alle Protokolle.</p></article>
    <article class="card"><span class="role-dot admin"></span><h3>Admin</h3><p>Inhalte, Team, Twitch-Konfiguration und Audit-Protokoll.</p></article>
    <article class="card"><span class="role-dot moderator"></span><h3>Moderator</h3><p>Inhalte bearbeiten und freigegebene Twitch-Mod-Tools nutzen.</p></article>
    <article class="card"><span class="role-dot viewer"></span><h3>Nur Lesen</h3><p>Kann alle allgemeinen Inhalte ansehen, aber nichts verändern.</p></article>
</section>

