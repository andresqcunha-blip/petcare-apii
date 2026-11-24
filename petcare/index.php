<?php
declare(strict_types=1);

/**
 * PetCare · API REST em PHP puro
 * Front controller: todas as rotas passam por aqui (ver .htaccess).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// ------------------------------------------------------------
// Utilitários de resposta
// ------------------------------------------------------------

function responder(int $codigo, mixed $dados = null): never
{
    http_response_code($codigo);
    if ($codigo !== 204 && $dados !== null) {
        echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

/** Erro simples: {"detail": "mensagem"} */
function erro(int $codigo, string $mensagem): never
{
    responder($codigo, ['detail' => $mensagem]);
}

/** Erro de validação (422): {"detail": ["erro 1", "erro 2"]} */
function erroValidacao(array $erros): never
{
    responder(422, ['detail' => array_values($erros)]);
}

set_exception_handler(function (Throwable $e) {
    error_log('PetCare: ' . $e->getMessage());
    erro(500, 'Erro interno do servidor');
});

// ------------------------------------------------------------
// Banco de dados (conexão preguiçosa: só abre quando precisa)
// ------------------------------------------------------------

function config(): array
{
    static $cfg = null;
    return $cfg ??= require __DIR__ . '/config.php';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config();
        $dsn = "mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function consultar(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

// ------------------------------------------------------------
// Requisição: corpo JSON e autenticação
// ------------------------------------------------------------

function corpoJson(): array
{
    $bruto = file_get_contents('php://input');
    if ($bruto === false || trim($bruto) === '') {
        erroValidacao(['Corpo da requisição vazio: envie um JSON']);
    }
    $dados = json_decode($bruto, true);
    // {} chega como array vazio: é um objeto válido (os campos faltantes são tratados depois)
    if (!is_array($dados) || ($dados !== [] && array_is_list($dados))) {
        erroValidacao(['Corpo inválido: envie um objeto JSON']);
    }
    return $dados;
}

function cabecalhoAuthorization(): string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $nome => $valor) {
            if (strcasecmp($nome, 'Authorization') === 0) {
                return $valor;
            }
        }
    }
    return '';
}

/** Devolve o token enviado no cabeçalho, ou encerra com 401. */
function tokenDaRequisicao(): string
{
    if (!preg_match('/^Bearer\s+([A-Za-z0-9]+)$/', trim(cabecalhoAuthorization()), $m)) {
        header('WWW-Authenticate: Bearer');
        erro(401, 'Token ausente ou inválido');
    }
    return $m[1];
}

/** Exige token válido e não expirado. Devolve o usuário dono do token. */
function exigirAutenticacao(): array
{
    $token = tokenDaRequisicao();
    $usuario = consultar(
        'SELECT u.id, u.nome, u.email
           FROM tokens t
           JOIN usuarios u ON u.id = t.usuario_id
          WHERE t.token = ? AND t.expira_em > NOW()',
        [$token]
    )->fetch();

    if (!$usuario) {
        header('WWW-Authenticate: Bearer');
        erro(401, 'Token ausente ou inválido');
    }
    return $usuario;
}

// ------------------------------------------------------------
// Validações
// ------------------------------------------------------------

function tamanho(string $s): int
{
    return mb_strlen(trim($s), 'UTF-8');
}

function validarTutor(array $d): array
{
    $erros = [];

    if (!isset($d['nome']) || !is_string($d['nome'])) {
        $erros[] = 'nome é obrigatório';
    } elseif (tamanho($d['nome']) < 2 || tamanho($d['nome']) > 100) {
        $erros[] = 'nome deve ter entre 2 e 100 caracteres';
    }

    if (!isset($d['telefone']) || !is_string($d['telefone']) || trim($d['telefone']) === '') {
        $erros[] = 'telefone é obrigatório';
    } elseif (tamanho($d['telefone']) > 20) {
        $erros[] = 'telefone deve ter no máximo 20 caracteres';
    }

    if (!isset($d['email']) || !is_string($d['email']) || trim($d['email']) === '') {
        $erros[] = 'email é obrigatório';
    } elseif (!filter_var(trim($d['email']), FILTER_VALIDATE_EMAIL) || tamanho($d['email']) > 150) {
        $erros[] = 'email inválido';
    }

    return $erros;
}

const ESPECIES = ['cachorro', 'gato', 'ave', 'roedor', 'outro'];

function validarPet(array $d): array
{
    $erros = [];

    if (!isset($d['nome']) || !is_string($d['nome'])) {
        $erros[] = 'nome é obrigatório';
    } elseif (tamanho($d['nome']) < 2 || tamanho($d['nome']) > 60) {
        $erros[] = 'nome deve ter entre 2 e 60 caracteres';
    }

    if (!isset($d['especie']) || !is_string($d['especie'])) {
        $erros[] = 'especie é obrigatória';
    } elseif (!in_array($d['especie'], ESPECIES, true)) {
        $erros[] = 'especie deve ser uma de: ' . implode(', ', ESPECIES);
    }

    if (isset($d['raca'])) {
        if (!is_string($d['raca'])) {
            $erros[] = 'raca deve ser texto';
        } elseif (tamanho($d['raca']) > 60) {
            $erros[] = 'raca deve ter no máximo 60 caracteres';
        }
    }

    if (!isset($d['peso_kg']) || is_bool($d['peso_kg']) || !is_numeric($d['peso_kg'])) {
        $erros[] = 'peso_kg é obrigatório e deve ser numérico';
    } elseif ((float) $d['peso_kg'] <= 0 || (float) $d['peso_kg'] > 200) {
        $erros[] = 'peso_kg deve ser maior que 0 e no máximo 200';
    }

    if (!isset($d['data_nascimento']) || !is_string($d['data_nascimento'])) {
        $erros[] = 'data_nascimento é obrigatória (AAAA-MM-DD)';
    } else {
        $data = DateTimeImmutable::createFromFormat('!Y-m-d', $d['data_nascimento']);
        if (!$data || $data->format('Y-m-d') !== $d['data_nascimento']) {
            $erros[] = 'data_nascimento deve estar no formato AAAA-MM-DD e ser uma data válida';
        } elseif ($data > new DateTimeImmutable('today')) {
            $erros[] = 'data_nascimento não pode ser futura';
        }
    }

    if (!isset($d['tutor_id']) || is_bool($d['tutor_id'])
        || !(is_int($d['tutor_id']) || (is_string($d['tutor_id']) && ctype_digit($d['tutor_id'])))) {
        $erros[] = 'tutor_id é obrigatório e deve ser um inteiro';
    } elseif (!consultar('SELECT 1 FROM tutores WHERE id = ?', [(int) $d['tutor_id']])->fetch()) {
        $erros[] = 'tutor_id não corresponde a um tutor existente';
    }

    return $erros;
}

// ------------------------------------------------------------
// Acesso a dados
// ------------------------------------------------------------

function buscarTutor(int $id): ?array
{
    $t = consultar('SELECT id, nome, telefone, email FROM tutores WHERE id = ?', [$id])->fetch();
    return $t ?: null;
}

function formatarPet(array $p): array
{
    $p['id']       = (int) $p['id'];
    $p['tutor_id'] = (int) $p['tutor_id'];
    $p['peso_kg']  = (float) $p['peso_kg'];
    return $p;
}

function buscarPet(int $id): ?array
{
    $p = consultar(
        'SELECT p.id, p.nome, p.especie, p.raca, p.data_nascimento, p.peso_kg,
                p.tutor_id, t.nome AS tutor_nome
           FROM pets p
           JOIN tutores t ON t.id = p.tutor_id
          WHERE p.id = ?',
        [$id]
    )->fetch();
    return $p ? formatarPet($p) : null;
}

function emailJaUsado(string $email, ?int $ignorarId = null): bool
{
    $sql = 'SELECT 1 FROM tutores WHERE email = ?';
    $par = [$email];
    if ($ignorarId !== null) {
        $sql .= ' AND id <> ?';
        $par[] = $ignorarId;
    }
    return (bool) consultar($sql, $par)->fetch();
}

function dadosTutor(array $d): array
{
    return [trim($d['nome']), trim($d['telefone']), trim($d['email'])];
}

function dadosPet(array $d): array
{
    $raca = isset($d['raca']) && trim($d['raca']) !== '' ? trim($d['raca']) : null;
    return [
        trim($d['nome']), $d['especie'], $raca,
        $d['data_nascimento'], round((float) $d['peso_kg'], 2), (int) $d['tutor_id'],
    ];
}

/** Executa um INSERT/UPDATE de tutor traduzindo violação de UNIQUE em 409. */
function gravarTutor(string $sql, array $params): void
{
    try {
        consultar($sql, $params);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            erro(409, 'Já existe um tutor com este e-mail');
        }
        throw $e;
    }
}

// ------------------------------------------------------------
// Rotas
// ------------------------------------------------------------

function rotaLogin(): never
{
    $d = corpoJson();
    $erros = [];
    if (!isset($d['email']) || !is_string($d['email']) || trim($d['email']) === '') {
        $erros[] = 'email é obrigatório';
    }
    if (!isset($d['senha']) || !is_string($d['senha']) || $d['senha'] === '') {
        $erros[] = 'senha é obrigatória';
    }
    if ($erros) {
        erroValidacao($erros);
    }

    $u = consultar('SELECT id, senha_hash FROM usuarios WHERE email = ?', [trim($d['email'])])->fetch();
    // Mesma mensagem para e-mail inexistente e senha errada (não revela qual falhou)
    $hash = $u['senha_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    if (!password_verify($d['senha'], $hash) || !$u) {
        erro(401, 'Credenciais inválidas');
    }

    $token = bin2hex(random_bytes(32));
    $horas = (int) config()['token_horas'];
    consultar(
        'INSERT INTO tokens (usuario_id, token, expira_em)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))',
        [$u['id'], $token, $horas]
    );
    // Limpa tokens vencidos deste usuário
    consultar('DELETE FROM tokens WHERE usuario_id = ? AND expira_em <= NOW()', [$u['id']]);

    $expira = consultar('SELECT expira_em FROM tokens WHERE token = ?', [$token])->fetch()['expira_em'];
    responder(200, ['token' => $token, 'tipo' => 'Bearer', 'expira_em' => $expira]);
}

function rotaLogout(): never
{
    exigirAutenticacao();
    consultar('DELETE FROM tokens WHERE token = ?', [tokenDaRequisicao()]);
    responder(204);
}

// ----- Tutores -----

function listarTutores(): never
{
    $lista = consultar('SELECT id, nome, telefone, email FROM tutores ORDER BY nome')->fetchAll();
    foreach ($lista as &$t) {
        $t['id'] = (int) $t['id'];
    }
    responder(200, ['total' => count($lista), 'tutores' => $lista]);
}

function mostrarTutor(int $id): never
{
    $t = buscarTutor($id) ?? erro(404, 'Tutor não encontrado');
    $t['id'] = (int) $t['id'];
    responder(200, $t);
}

function petsDoTutor(int $id): never
{
    buscarTutor($id) ?? erro(404, 'Tutor não encontrado');
    $pets = consultar(
        'SELECT id, nome, especie, raca, data_nascimento, peso_kg, tutor_id
           FROM pets WHERE tutor_id = ? ORDER BY nome',
        [$id]
    )->fetchAll();
    $pets = array_map('formatarPet', $pets);
    responder(200, ['tutor_id' => $id, 'total' => count($pets), 'pets' => $pets]);
}

function criarTutor(): never
{
    exigirAutenticacao();
    $d = corpoJson();
    if ($erros = validarTutor($d)) {
        erroValidacao($erros);
    }
    [$nome, $tel, $email] = dadosTutor($d);
    if (emailJaUsado($email)) {
        erro(409, 'Já existe um tutor com este e-mail');
    }
    gravarTutor('INSERT INTO tutores (nome, telefone, email) VALUES (?, ?, ?)', [$nome, $tel, $email]);
    responder(201, ['id' => (int) db()->lastInsertId()]);
}

function alterarTutor(int $id): never
{
    exigirAutenticacao();
    buscarTutor($id) ?? erro(404, 'Tutor não encontrado');
    $d = corpoJson();
    if ($erros = validarTutor($d)) {
        erroValidacao($erros);
    }
    [$nome, $tel, $email] = dadosTutor($d);
    if (emailJaUsado($email, $id)) {
        erro(409, 'Já existe um tutor com este e-mail');
    }
    gravarTutor(
        'UPDATE tutores SET nome = ?, telefone = ?, email = ? WHERE id = ?',
        [$nome, $tel, $email, $id]
    );
    mostrarTutor($id);
}

function excluirTutor(int $id): never
{
    exigirAutenticacao();
    buscarTutor($id) ?? erro(404, 'Tutor não encontrado');

    $qtd = (int) consultar('SELECT COUNT(*) AS n FROM pets WHERE tutor_id = ?', [$id])->fetch()['n'];
    if ($qtd > 0) {
        erro(409, "Não é possível excluir: o tutor ainda tem $qtd pet(s). Exclua ou transfira os pets antes.");
    }
    try {
        consultar('DELETE FROM tutores WHERE id = ?', [$id]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') { // um pet foi criado no meio do caminho
            erro(409, 'Não é possível excluir: o tutor ainda tem pets cadastrados');
        }
        throw $e;
    }
    responder(204);
}

// ----- Pets -----

function listarPets(): never
{
    $sql = 'SELECT id, nome, especie, raca, data_nascimento, peso_kg, tutor_id FROM pets';
    $par = [];
    if (isset($_GET['especie']) && $_GET['especie'] !== '') {
        $sql .= ' WHERE especie = ?';
        $par[] = (string) $_GET['especie'];
    }
    $pets = consultar($sql . ' ORDER BY nome', $par)->fetchAll();
    $pets = array_map('formatarPet', $pets);
    responder(200, ['total' => count($pets), 'pets' => $pets]);
}

function mostrarPet(int $id): never
{
    responder(200, buscarPet($id) ?? erro(404, 'Pet não encontrado'));
}

function criarPet(): never
{
    exigirAutenticacao();
    $d = corpoJson();
    if ($erros = validarPet($d)) {
        erroValidacao($erros);
    }
    consultar(
        'INSERT INTO pets (nome, especie, raca, data_nascimento, peso_kg, tutor_id)
         VALUES (?, ?, ?, ?, ?, ?)',
        dadosPet($d)
    );
    responder(201, ['id' => (int) db()->lastInsertId()]);
}

function alterarPet(int $id): never
{
    exigirAutenticacao();
    buscarPet($id) ?? erro(404, 'Pet não encontrado');
    $d = corpoJson();
    if ($erros = validarPet($d)) {
        erroValidacao($erros);
    }
    consultar(
        'UPDATE pets SET nome = ?, especie = ?, raca = ?, data_nascimento = ?,
                         peso_kg = ?, tutor_id = ? WHERE id = ?',
        [...dadosPet($d), $id]
    );
    mostrarPet($id);
}

function excluirPet(int $id): never
{
    exigirAutenticacao();
    buscarPet($id) ?? erro(404, 'Pet não encontrado');
    consultar('DELETE FROM pets WHERE id = ?', [$id]);
    responder(204);
}

// ------------------------------------------------------------
// Roteador
// ------------------------------------------------------------

$metodo = $_SERVER['REQUEST_METHOD'];
if ($metodo === 'OPTIONS') {
    responder(204);
}

$caminho = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
if ($base !== '' && str_starts_with($caminho, $base)) {
    $caminho = substr($caminho, strlen($base));
}
$caminho = trim($caminho, '/');
if (str_starts_with($caminho, 'index.php')) {
    $caminho = trim(substr($caminho, strlen('index.php')), '/');
}
$partes = $caminho === '' ? [] : explode('/', $caminho);

$recurso = $partes[0] ?? '';
$temId   = isset($partes[1]) && ctype_digit($partes[1]);
$id      = $temId ? (int) $partes[1] : 0;
$n       = count($partes);

switch (true) {
    case $recurso === 'login' && $n === 1 && $metodo === 'POST':
        rotaLogin();
    case $recurso === 'logout' && $n === 1 && $metodo === 'POST':
        rotaLogout();

    case $recurso === 'tutores' && $n === 1 && $metodo === 'GET':
        listarTutores();
    case $recurso === 'tutores' && $n === 1 && $metodo === 'POST':
        criarTutor();
    case $recurso === 'tutores' && $n === 2 && $temId && $metodo === 'GET':
        mostrarTutor($id);
    case $recurso === 'tutores' && $n === 2 && $temId && $metodo === 'PUT':
        alterarTutor($id);
    case $recurso === 'tutores' && $n === 2 && $temId && $metodo === 'DELETE':
        excluirTutor($id);
    case $recurso === 'tutores' && $n === 3 && $temId && $partes[2] === 'pets' && $metodo === 'GET':
        petsDoTutor($id);

    case $recurso === 'pets' && $n === 1 && $metodo === 'GET':
        listarPets();
    case $recurso === 'pets' && $n === 1 && $metodo === 'POST':
        criarPet();
    case $recurso === 'pets' && $n === 2 && $temId && $metodo === 'GET':
        mostrarPet($id);
    case $recurso === 'pets' && $n === 2 && $temId && $metodo === 'PUT':
        alterarPet($id);
    case $recurso === 'pets' && $n === 2 && $temId && $metodo === 'DELETE':
        excluirPet($id);

    default:
        erro(404, 'Rota não encontrada');
}
