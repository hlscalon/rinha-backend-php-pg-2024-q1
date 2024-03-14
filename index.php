<?php

function respondAndDie(int $statusCode = 200, ?array $response = null) {
	header("Content-type: application/json");
	http_response_code($statusCode);

	if ($response !== null) {
		echo json_encode($response);
	}

	exit;
}

function getConnection(): PDO {
	$dsn = sprintf(
		"pgsql:host=%s;port=%s;dbname=%s",
		getenv("DB_HOST"),
		getenv("DB_PORT"),
		getenv("DB_NAME"),
	);

	return new PDO(
		$dsn,
		getenv("DB_USER"),
		getenv("DB_PASSWORD"),
		[PDO::ATTR_PERSISTENT => true]
	);
}

function getQueryTransacao(string $tipo): string {
	if ($tipo === "c") {
		$query = "UPDATE cliente SET saldo = saldo + ? WHERE id = ? RETURNING saldo, limite";
	} else {
		$query = "UPDATE cliente SET saldo = saldo - ? WHERE id = ? AND saldo - ? >= -ABS(limite) RETURNING saldo, limite";
	}

	return (
		"WITH cliente_atualizado AS ($query) " .
		"INSERT INTO transacao (cliente_id, valor, tipo, descricao) " .
			"SELECT ?, ?, ?, ? " .
			"FROM cliente_atualizado " .
			"RETURNING (SELECT CONCAT(limite, '|', saldo) FROM cliente WHERE id = cliente_id) AS limite_saldo "
	);
}

function handleTransacao(int $clienteId) {
	if ($_SERVER["REQUEST_METHOD"] !== "POST") {
		respondAndDie(404);
	}

	$request = json_decode(file_get_contents("php://input"), true);

	if (empty($request) || !is_array($request)) {
		respondAndDie(422);
	}

	if (filter_var($request["valor"], FILTER_VALIDATE_INT) === false || $request["valor"] <= 0) {
		respondAndDie(422);
	}

	if ($request["tipo"] !== "c" && $request["tipo"] !== "d") {
		respondAndDie(422);
	}

	if (empty($request["descricao"])) {
		respondAndDie(422);
	}

	$descricaoLen = strlen($request["descricao"]);
	if ($descricaoLen < 1 || $descricaoLen > 10) {
		respondAndDie(422);
	}
	
	if ($request["tipo"] == "c") {
		$params = [$request["valor"], $clienteId];
	} else {
		$params = [$request["valor"], $clienteId, $request["valor"]];
	}

	$params = array_merge($params, [$clienteId, $request["valor"], $request["tipo"], $request["descricao"]]);
	$query = getQueryTransacao($request["tipo"]);

	$stmt = getConnection()->prepare($query);
	$stmt->execute($params);
	$result = $stmt->fetchAll();

	if (empty($result) || empty($result[0])) {
		respondAndDie(422);
	}

	$limiteSaldo = explode("|", $result[0]["limite_saldo"]);

	$response = [
		"saldo" => $limiteSaldo[1],
		"limite" => $limiteSaldo[0],
	];

	respondAndDie(200, $response);
}

function handleExtrato(int $clienteId) {
	if ($_SERVER["REQUEST_METHOD"] !== "GET") {
		respondAndDie(404);
	}

	$query = (
		"SELECT t.valor, t.tipo, t.descricao, t.realizada_em, c.limite, c.saldo " .
		"FROM cliente c " .
		"LEFT JOIN transacao t ON c.id = t.cliente_id " .
		"WHERE c.id = ? " .
		"ORDER BY t.id DESC " .
		"LIMIT 10 "
	);

	$conn = getConnection();
	$stmt = $conn->prepare($query);
	$stmt->execute([$clienteId]);
	$result = $stmt->fetchAll();

	if ($result === false) {
		respondAndDie(422);
	}

	$limite = $result[0]["limite"];
	$saldo = $result[0]["saldo"];

	$ultimasTransacoes = [];
	foreach ($result as $row) {
		if ($row["valor"] !== null) {
			$ultimasTransacoes[] = [
				"valor" => $row["valor"],
				"tipo" => $row["tipo"],
				"descricao" => $row["descricao"],
				"realizada_em" => $row["realizada_em"],
			];
		}
	}

	$response = [
		"saldo" => [
			"total" => $saldo,
			"limite" => $limite,
			"data_extrato" => date("Y-m-d H:i:s"), // verificar essa data
		],
		"ultimas_transacoes" => $ultimasTransacoes,
	];

	respondAndDie(200, $response);
}

function handleCliente() {
	$uri = $_SERVER["REQUEST_URI"];
	$uriParts = explode("/", $uri);
	if (count($uriParts) != 4) {
		respondAndDie(404);
	}

	$id = $uriParts[2];
	if (filter_var($id, FILTER_VALIDATE_INT) === false) {
		respondAndDie(422);
	}

	// Regra de negócio da aplicação
	if ($id < 1 || $id > 5) {
		respondAndDie(404);
	}

	if ($uriParts[3] === "transacoes") {
		return handleTransacao($id);
	} else if ($uriParts[3] === "extrato") {
		return handleExtrato($id);
	}

	respondAndDie(404);
}

function main() {
	handleCliente();
}

main();