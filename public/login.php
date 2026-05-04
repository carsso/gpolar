<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/helpers.php';

use GPolar\PolarstepsClient;

// Already logged in?
if (getToken()) {
    header('Location: /index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? 'credentials';

    if ($mode === 'token') {
        $token = trim($_POST['token'] ?? '');
        if (!$token) {
            $error = 'Le token ne peut pas être vide.';
        } else {
            try {
                $client = new PolarstepsClient($token);
                $client->getMe();
                setToken($token);
                header('Location: /index.php');
                exit;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if (!$username || !$password) {
            $error = 'Email et mot de passe requis.';
        } else {
            try {
                $token = PolarstepsClient::loginWithCredentials($username, $password);
                setToken($token);
                header('Location: /index.php');
                exit;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

?>
<?= htmlHead('Connexion') ?>

<div class="min-h-screen flex flex-col items-center justify-center px-4 py-12 gap-8">

  <!-- Logo -->
  <div class="text-center">
    <div class="text-5xl mb-2">🚴</div>
    <h1 class="text-2xl font-bold text-gray-50">GPolar</h1>
    <p class="text-gray-500 text-sm mt-1">Suis les aventures de tes amis, enfin.</p>
  </div>

  <!-- Login card -->
  <div class="w-full max-w-md bg-gray-900 rounded-2xl shadow-sm border border-gray-800 p-6 sm:p-8">

    <?php if ($error): ?>
    <div class="mb-4 bg-red-950 border border-red-900 text-red-300 text-sm rounded-xl px-4 py-3">
      <?= esc($error) ?>
    </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="flex rounded-xl overflow-hidden border border-gray-700 mb-5 text-sm font-medium">
      <button onclick="switchTab('credentials')" id="tab-credentials"
        class="flex-1 py-2 transition-colors bg-amber-500 text-white">
        Email / Mot de passe
      </button>
      <button onclick="switchTab('token')" id="tab-token"
        class="flex-1 py-2 transition-colors text-gray-500 hover:bg-gray-800">
        Token (avancé)
      </button>
    </div>

    <!-- Form: credentials -->
    <form method="POST" id="form-credentials" class="space-y-4">
      <input type="hidden" name="mode" value="credentials">
      <div>
        <label for="username" class="block text-sm font-medium text-gray-300 mb-1.5">Email ou nom d'utilisateur</label>
        <input type="text" id="username" name="username"
          value="<?= esc($_POST['username'] ?? '') ?>"
          class="w-full rounded-xl border border-gray-700 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400 focus:border-transparent bg-gray-800 text-gray-100 placeholder-gray-600"
          placeholder="ton@email.com" autocomplete="username" required>
      </div>
      <div>
        <label for="password" class="block text-sm font-medium text-gray-300 mb-1.5">Mot de passe</label>
        <input type="password" id="password" name="password"
          class="w-full rounded-xl border border-gray-700 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400 focus:border-transparent bg-gray-800 text-gray-100 placeholder-gray-600"
          placeholder="••••••••" autocomplete="current-password" required>
      </div>
      <button type="submit"
        class="w-full bg-amber-500 hover:bg-amber-400 text-white font-semibold py-2.5 rounded-xl transition-colors text-sm">
        Se connecter
      </button>
    </form>

    <!-- Form: token -->
    <form method="POST" id="form-token" class="space-y-4 hidden">
      <input type="hidden" name="mode" value="token">
      <div>
        <label for="token" class="block text-sm font-medium text-gray-300 mb-1.5">
          Token (<code class="text-xs bg-gray-800 text-gray-300 px-1 rounded">remember_token</code>)
        </label>
        <input type="password" id="token" name="token"
          class="w-full rounded-xl border border-gray-700 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400 focus:border-transparent font-mono bg-gray-800 text-gray-100 placeholder-gray-600"
          placeholder="Colle ton token ici…" autocomplete="off">
      </div>
      <button type="submit"
        class="w-full bg-amber-500 hover:bg-amber-400 text-white font-semibold py-2.5 rounded-xl transition-colors text-sm">
        Se connecter avec le token
      </button>
    </form>
  </div>

  <!-- Token hint -->
  <div id="token-hint" class="w-full max-w-md bg-gray-900 rounded-2xl shadow-sm border border-gray-800 p-6 sm:p-8 hidden">
    <h2 class="font-semibold text-gray-100 mb-3 flex items-center gap-2">
      <span>🔍</span> Où trouver ton token ?
    </h2>
    <ol class="space-y-3 text-sm text-gray-400">
      <li class="flex gap-3">
        <span class="font-bold text-amber-500 flex-shrink-0">1.</span>
        <span>Connecte-toi sur <strong>polarsteps.com</strong> dans ton navigateur.</span>
      </li>
      <li class="flex gap-3">
        <span class="font-bold text-amber-500 flex-shrink-0">2.</span>
        <span>Ouvre les DevTools (<kbd class="bg-gray-800 border border-gray-700 text-gray-300 rounded px-1.5 py-0.5 text-xs font-mono">F12</kbd> ou <kbd class="bg-gray-800 border border-gray-700 text-gray-300 rounded px-1.5 py-0.5 text-xs font-mono">⌘⌥I</kbd>).</span>
      </li>
      <li class="flex gap-3">
        <span class="font-bold text-amber-500 flex-shrink-0">3.</span>
        <span>Va dans l'onglet <strong>Stockage</strong> (Firefox) ou <strong>Application</strong> (Chrome) → <strong>Cookies</strong> → <code class="bg-gray-800 text-gray-300 px-1 rounded text-xs">https://www.polarsteps.com</code>.</span>
      </li>
      <li class="flex gap-3">
        <span class="font-bold text-amber-500 flex-shrink-0">4.</span>
        <span>Trouve la ligne <code class="bg-gray-800 text-gray-300 px-1 rounded text-xs">remember_token</code>, copie la <strong>Valeur</strong> et colle-la ci-dessus.</span>
      </li>
    </ol>
    <p class="mt-4 text-xs text-gray-500">
      ⚠️ Le cookie est <strong>HttpOnly</strong> — impossible à lire en JavaScript, mais visible dans les DevTools.
    </p>
  </div>

</div>

<script>
function switchTab(tab) {
  const isToken = tab === 'token';
  document.getElementById('form-credentials').classList.toggle('hidden', isToken);
  document.getElementById('form-token').classList.toggle('hidden', !isToken);
  document.getElementById('token-hint').classList.toggle('hidden', !isToken);
  document.getElementById('tab-credentials').className =
    'flex-1 py-2 transition-colors ' + (isToken ? 'text-gray-500 hover:bg-gray-800' : 'bg-amber-500 text-white');
  document.getElementById('tab-token').className =
    'flex-1 py-2 transition-colors ' + (isToken ? 'bg-amber-500 text-white' : 'text-gray-500 hover:bg-gray-800');
}
<?php if (($_POST['mode'] ?? '') === 'token'): ?>switchTab('token');<?php endif; ?>
</script>
</body>
</html>
