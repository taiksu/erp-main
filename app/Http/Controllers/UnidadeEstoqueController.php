<?php

namespace App\Http\Controllers;

use App\Mail\NovoPedidoMail;
use App\Models\ControleSaldoEstoque;
use App\Models\Fornecedor;
use App\Models\HistoricoPedido;
use App\Models\MovimentacoesEstoque;

use App\Models\UnidadeEstoque;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class UnidadeEstoqueController extends Controller
{
    public function consumirEstoque(Request $request)
    {
        $usuario = Auth::user();
    
        if ($usuario->pin !== $request->pin) {
            return response()->json(['error' => 'PIN incorreto'], 403);
        }
    
        $validatedData = $request->validate([
            'itens' => 'required|array',
            'itens.*.id' => 'required|integer|exists:lista_produtos,id',
            'itens.*.quantidade' => 'required|numeric|min:0',
        ]);
    
        DB::beginTransaction();
    
        try {
            foreach ($validatedData['itens'] as $item) {
                $quantidadeRestante = floatval($item['quantidade']);
                $estoques = DB::table('unidade_estoque')
                    ->where('insumo_id', $item['id'])
                    ->where('unidade_id', Auth::user()->unidade_id)
                    ->orderBy('created_at', 'asc') // FIFO
                    ->get();
    
                foreach ($estoques as $estoque) {
                    if ($quantidadeRestante <= 0) {
                        break;
                    }
    
                    $quantidadeConsumir = min($estoque->quantidade, $quantidadeRestante);
    
                    // Nova quantidade após retirada
                    $novaQuantidade = $estoque->quantidade - $quantidadeConsumir;
    
                    // Se a quantidade restante for zero ou negativa, excluir o registro
                    if ($novaQuantidade <= 0) {
                        DB::table('unidade_estoque')
                            ->where('id', $estoque->id)
                            ->delete();
                    } else {
                        // Caso contrário, atualizar apenas a quantidade
                        DB::table('unidade_estoque')
                            ->where('id', $estoque->id)
                            ->update([
                                'quantidade' => $novaQuantidade,
                            ]);
                    }
    
                    // Calcular o valor consumido para registrar na movimentação
                    $valorConsumido = $estoque->preco_insumo * $quantidadeConsumir;
    
                    // Registrar movimentação com o preço por unidade/quilo
                    MovimentacoesEstoque::create([
                        'insumo_id' => $item['id'],
                        'fornecedor_id' => $estoque->fornecedor_id,
                        'usuario_id' => Auth::id(),
                        'quantidade' => $quantidadeConsumir,
                        'preco_insumo' => $valorConsumido, // Valor total consumido
                        'operacao' => 'Retirada',
                        'unidade' => $estoque->unidade,
                        'unidade_id' => Auth::user()->unidade_id,
                    ]);
    
                    $quantidadeRestante -= $quantidadeConsumir;
                }
    
                if ($quantidadeRestante > 0) {
                    throw new \Exception("Estoque insuficiente para o produto ID {$item['id']}");
                }
            }
    
            $unidade_id = Auth::user()->unidade_id;
    
            // Calcular o valor total do estoque
            $valorInsumos = UnidadeEstoque::where('unidade_id', $unidade_id)
                ->where('quantidade', '>', 0)
                ->sum(DB::raw('preco_insumo * quantidade'));
    
            $saldoAtual = $valorInsumos;
    
            DB::table('controle_saldo_estoques')->insert([
                'ajuste_saldo' => $saldoAtual,
                'data_ajuste' => now(),
                'motivo_ajuste' => 'Atualização após retirada',
                'unidade_id' => $unidade_id,
                'responsavel_id' => Auth::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    
            DB::commit();
    
            Auth::guard('web')->logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();
    
            return response()->json([
                'message' => 'Operação realizada com sucesso! O usuário foi desconectado.',
                'redirect_url' => route('login.pagina.estoque')
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Erro ao retirar os itens: ' . $e->getMessage()], 500);
        }
    }
    // Cria novos pedidos
    public function criarPedido(Request $request)
    {
        Log::info('Início do método criarPedido', ['request' => $request->all()]);

        try {
            $itens = $request->itens;
            Log::info('Itens do pedido recebidos', ['itens' => $itens]);

            $fornecedorId = $itens[0]['fornecedor_id'];
            Log::info('ID do fornecedor extraído', ['fornecedor_id' => $fornecedorId]);

            // Buscar o fornecedor e acessar todas as colunas
            $fornecedor = Fornecedor::findOrFail($fornecedorId);
            Log::info('Fornecedor encontrado', ['fornecedor' => $fornecedor]);

            // Obter a unidade do usuário autenticado
            $unidade = Auth::user()->unidade;
            $nomeUnidade = $unidade->cidade ?? 'Unidade Desconhecida'; // Usando cidade para nome da unidade
            $dataPedido = now()->format('d/m/Y'); // Data atual no formato dd/mm/aaaa

            // Criar o pedido
            $pedido = HistoricoPedido::create([
                'status_pedido' => 'enviado',
                'itens_id' => json_encode($itens),
                'quantidade' => array_sum(array_column($itens, 'quantidade')),
                'valor_unitario' => array_column($itens, 'valor_unitario'),
                'valor_total_item' => array_column($itens, 'valor_total_item'),
                'valor_total_carrinho' => $request->valor_total_carrinho,
                'unidade_id' => Auth::user()->unidade_id,
                'usuario_responsavel_id' => Auth::user()->id,
                'fornecedor_id' => $fornecedor->id,
                'nome_primeiro_fornecedor' => $fornecedor->nome,
            ]);
            Log::info('Pedido criado com sucesso', ['pedido' => $pedido]);

            // Gerar o PDF
            $fileName = $this->gerarPdf($pedido, $itens, $fornecedor);
            Log::info('PDF gerado', ['fileName' => $fileName]);

            // Recuperar o e-mail do fornecedor
            $emailFornecedor = $fornecedor->email;
            Log::info('E-mail do fornecedor recuperado', ['email' => $emailFornecedor]);

            // Recuperar o e-mail do usuário autenticado (responsável)
            $emailUsuario = Auth::user()->email;

            // Recuperar o e-mail da franqueadora (caso tenha, ou pode ser um e-mail fixo)
            $emailFranqueadora = 'taiksusushi@gmail.com'; // Substitua pelo e-mail da franqueadora

            // Enviar o e-mail para o fornecedor com o PDF, incluindo o nome da unidade e a data do pedido
            if ($emailFornecedor) {
                Mail::to($emailFornecedor)
                    ->cc([$emailUsuario, $emailFranqueadora]) // Adiciona o usuário e a franqueadora em cópia
                    ->send(new NovoPedidoMail($pedido, $fileName, Auth::user()->name, $nomeUnidade, $dataPedido));

                Log::info('E-mail enviado para o fornecedor, com cópia para o usuário e franqueadora', [
                    'email' => $emailFornecedor,
                    'cc' => [$emailUsuario, $emailFranqueadora],
                ]);
            }

            return response()->json([
                'pedido' => $pedido,
                'pdf' => $fileName,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Erro ao criar pedido', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Ocorreu um erro ao criar o pedido. Verifique os logs para mais detalhes.',
            ], 500);
        }
    }

    // Gera os pdf dos pedidos
    public function gerarPdf($pedido, $itens, $fornecedor)
    {
        // Preparando os dados dos produtos para o PDF diretamente da request
        $produtosParaPdf = array_map(function ($item) {
            return [
                'nome' => $item['nome'],
                // Formata a quantidade de acordo com a unidade de medida:
                'quantidade' => $item['unidadeDeMedida'] === 'a_granel'
                    ? number_format($item['quantidade'], 3, ',', '.')
                    : number_format($item['quantidade'], 0, ',', '.'),
                'valor_unitario' => number_format($item['valor_unitario'], 2, ',', '.'), // Convertendo para reais
                'valor_total_item' => number_format($item['valor_total_item'], 2, ',', '.'), // Convertendo para reais
                'unidade_de_medida' => $item['unidadeDeMedida'] === 'a_granel' ? 'KG' : 'UN', // Definindo a unidade de medida
            ];
        }, $itens);

        // Obter a unidade do usuário autenticado
        $unidade = Auth::user()->unidade; // Acessando o relacionamento
        $nomeUsuario = Auth::user()->name ?? 'Não informado';
        $nomeUnidade = $unidade->cidade ?? 'Unidade Desconhecida'; // Usando 'cidade' para o nome da unidade
        $nomeFornecedor = $fornecedor ?? 'não informado';

        // Data do dia em formato brasileiro
        $dataAtual = now()->format('d/m/Y');

        // Configurando o DomPDF
        $pdfOptions = new Options();
        $pdfOptions->set('isHtml5ParserEnabled', true);
        $pdfOptions->set('isPhpEnabled', true);

        $dompdf = new Dompdf($pdfOptions);

        // Gerando o HTML do PDF
        $html = view('pedido.pdf', compact('produtosParaPdf', 'pedido', 'nomeUnidade', 'dataAtual', 'nomeFornecedor', 'nomeUsuario'))->render();
        $dompdf->loadHtml($html);
        $dompdf->render();

        // Gerar o nome do arquivo
        $fileName = "pedido_{$nomeUnidade}_{$pedido->id}.pdf";

        // Caminho completo para o diretório public
        $path = public_path('storage/pedidos');

        // Verificar se o diretório existe, caso contrário, criar
        if (!file_exists($path)) {
            mkdir($path, 0777, true);
        }

        // Salvar o PDF no diretório public
        file_put_contents("{$path}/{$fileName}", $dompdf->output());

        return $fileName;
    }

    // Lista todos os produtos do estoque!
    public function index()
    {
        // Obtém a unidade do usuário autenticado
        $unidadeId = Auth::user()->unidade_id;
    
        $estoques = UnidadeEstoque::with(['insumo', 'fornecedor', 'categoriaProduto'])
            ->where('unidade_id', $unidadeId)
            ->orderBy('created_at', 'desc')
            ->get();
    
        // Agrupa os estoques pelo insumo
        $produtosAgrupados = $estoques->groupBy('insumo_id')->map(function ($lotes) {
            // Pega o primeiro lote para obter informações do produto
            $primeiroLote = $lotes->first();
            $insumo = $primeiroLote->insumo;
    
            // Obtém o nome da categoria, garantindo que nunca seja null
            $categoriaNome = optional($insumo->categoriaProduto)->nome ?? 'Sem Categoria';
    
            // Filtra os lotes com quantidade > 0
            $lotesDisponiveis = $lotes->filter(fn($lote) => $lote->quantidade > 0);
    
            // Se não houver lotes disponíveis, não exibe o produto
            if ($lotesDisponiveis->isEmpty()) {
                return null;
            }
    
            // Calcular a soma do valor total dos lotes
            $valorTotalLotes = $lotesDisponiveis->sum(fn($lote) => $lote->preco_insumo * $lote->quantidade);
    
            return [
                'id' => $insumo->id,
                'nome' => $insumo->nome,
                'profile_photo' => $insumo->profile_photo,
                'categoria' => $categoriaNome,
                'unidadeDeMedida' => $insumo->unidadeDeMedida,
                'lotes' => $lotesDisponiveis->map(function ($lote) use ($insumo) {
                    $valorTotal = $lote->preco_insumo * $lote->quantidade;
    
                    return [
                        'id' => $lote->id,
                        'data' => $lote->created_at->format('d/m/Y'),
                        'fornecedor' => $lote->fornecedor->razao_social ?? 'N/A',
                        'quantidade' => $lote->quantidade,
                        'preco_insumo' => number_format($lote->preco_insumo, 2, ',', '.'), // Preço por unidade (kg ou item)
                        'valor_total' => number_format($valorTotal, 2, ',', '.'), // Valor total do lote
                    ];
                })->values(),
                'valor_total_lote' => number_format($valorTotalLotes, 2, ',', '.'), // Soma dos valores totais dos lotes
             
            ];
        })->filter();
    
        // Agrupar os produtos por categoria
        $produtosPorCategoria = $produtosAgrupados->groupBy('categoria')->map(function ($produtos, $categoria) {
            return [
                'categoria_nome' => $categoria,
                'produtos' => $produtos->values(),
            ];
        })->values();
    
        // Retorna os dados no formato JSON
        return response()->json($produtosPorCategoria);
    }

    // Lista todos os produtos do estoque!
    public function ListaProdutoEstoque()
    {
        // Obtém a unidade do usuário autenticado
        $unidadeId = Auth::user()->unidade_id;

        // Filtra os estoques pela unidade
        $estoques = UnidadeEstoque::with(['insumo', 'fornecedor'])
            ->where('unidade_id', $unidadeId)
            ->orderBy('created_at', 'desc')
            ->get();

        // Variável para somar a quantidade total de todos os itens
        $quantidadeTotalGeral = 0;

        // Agrupa os estoques pelo insumo
        $produtosAgrupados = $estoques->groupBy('insumo_id')->map(function ($lotes) use (&$quantidadeTotalGeral) {
            // Pega o primeiro lote para obter informações do produto
            $primeiroLote = $lotes->first();
            $insumo = $primeiroLote->insumo;

            // Filtra os lotes com quantidade > 0
            $lotesDisponiveis = $lotes->filter(function ($lote) {
                return $lote->quantidade > 0; // Só manter os lotes com estoque maior que 0
            });

            // Se não houver lotes disponíveis, não exibe o produto
            if ($lotesDisponiveis->isEmpty()) {
                return null; // Retorna null para ocultar o produto
            }

            // Calcular a soma do valor total do lote (valor unitário * quantidade)
            $valorTotalLotes = $lotesDisponiveis->sum(function ($lote) {
                return ($lote->preco_insumo) * $lote->quantidade;
            });

            // Calcular a soma da quantidade total para este produto
            $quantidadeTotalProduto = $lotesDisponiveis->sum('quantidade');
            $quantidadeTotalGeral += $quantidadeTotalProduto; // Atualiza o total geral

            // Calcular a soma do valor pago por quilo para os produtos 'a_granel'
            $valorPagoPorQuiloLote = null;
            if ($insumo->unidadeDeMedida === 'a_granel') {
                $valorPagoPorQuiloLote = $lotesDisponiveis->sum(function ($lote) {
                    return ($lote->preco_insumo); // Soma apenas o valor unitário (não multiplicado pela quantidade)
                });
            }

            // Determinar o número de casas decimais para controle interno (opcional)
            $casasDecimaisQuantidade = $insumo->unidadeDeMedida === 'a_granel' ? 3 : 0;

            // Arredondar apenas se necessário, mantendo como número
            $quantidadeTotalAjustada = round($quantidadeTotalProduto, $casasDecimaisQuantidade);

            return [
                'id' => $insumo->id,
                'nome' => $insumo->nome,
                'profile_photo' => $insumo->profile_photo,
                'categoria' => $insumo->categoria,
                'unidadeDeMedida' => $insumo->unidadeDeMedida,
                'lotes' => $lotesDisponiveis->map(function ($lote) use ($insumo) {
                    // Calcular o valor unitário
                    $valorUnitario = $lote->preco_insumo;
                    $valorTotal = $valorUnitario * $lote->quantidade; // Valor total do lote

                    // Calcular o valor pago por quilo, se for 'a_granel'
                    $valorPagoPorQuilo = $insumo->unidadeDeMedida === 'a_granel'
                        ? 'R$ ' . number_format($valorUnitario, 2, ',', '.') // Apenas o valor unitário
                        : null;

                    return [
                        'id' => $lote->id,
                        'unidadeDeMedida' => $insumo->unidadeDeMedida,
                        'data' => $lote->created_at->format('d/m/Y'),
                        'fornecedor' => $lote->fornecedor->razao_social,
                        'quantidade' => $lote->quantidade,
                        'preco_unitario' => 'R$ ' . number_format($valorUnitario, 2, ',', '.'),
                        'valor_total' => 'R$ ' . number_format($valorTotal, 2, ',', '.'),
                        // Adicionando cálculo do valor pago por quilo para produtos 'kg'
                        'valor_pago_por_quilo' => $lote->unidade === 'kg'
                            ? 'R$ ' . number_format(($lote->preco_insumo) / $lote->quantidade, 2, ',', '.')
                            : null,
                    ];
                }),
                // Soma o valor total de todos os lotes do insumo
                'valor_total_lote' => 'R$ ' . number_format($valorTotalLotes, 2, ',', '.'),
                // Soma do valor pago por quilo de todos os lotes do insumo
                'valor_pago_por_quilo_lote' => $valorPagoPorQuiloLote !== null
                    ? 'R$ ' . number_format($valorPagoPorQuiloLote, 2, ',', '.') : null,
                // Quantidade total do produto
                'quantidade_total' => $quantidadeTotalAjustada, // Retornado como número
            ];
        })->filter(); // Remove os produtos que foram marcados como null

        // Retorna os dados no formato JSON com o total geral de itens
        return response()->json([
            'produtos' => $produtosAgrupados,
            'quantidade_total_geral' => $quantidadeTotalGeral,
        ]);
    }



    public function painelInicialEstoque(Request $request)
    {
        $unidadeId = Auth::user()->unidade_id;
    
        // Obter as datas de início e fim
        try {
            $startDate = $request->input('start_date', Carbon::now()->startOfDay()->format('d-m-Y'));
            $endDate = $request->input('end_date', Carbon::now()->endOfDay()->format('d-m-Y'));
    
            $startDateConverted = Carbon::createFromFormat('d-m-Y', $startDate)->startOfDay();
            $endDateConverted = Carbon::createFromFormat('d-m-Y', $endDate)->endOfDay();
        } catch (\Exception $e) {
            return response()->json(['error' => 'Formato de data inválido. Use o formato DD-MM-YYYY.'], 400);
        }
    
        // Histórico de movimentações filtrado por data
        $historicoMovimentacoes = MovimentacoesEstoque::with(['insumo', 'usuario'])
            ->where('unidade_id', $unidadeId)
            ->whereBetween('created_at', [$startDateConverted, $endDateConverted])
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($estoque) {
                $quantidade = match ($estoque->operacao) {
                    'Entrada' => $estoque->quantidade,
                    'Retirada' => -$estoque->quantidade,
                    default => $estoque->quantidade,
                };
    
                if ($quantidade == 0) {
                    return null;
                }
    
                return [
                    'operacao' => $estoque->operacao,
                    'unidade' => $estoque->unidade,
                    'quantidade' => $quantidade,
                    'item' => $estoque->insumo->nome ?? 'N/A',
                    'data' => $estoque->created_at->format('d/m/Y - H:i:s'),
                    'responsavel' => $estoque->usuario->name ?? 'Desconhecido',
                ];
            })->filter();
    
        // Dados principais do painel
        $estoque = UnidadeEstoque::where('unidade_id', $unidadeId)
            ->where('quantidade', '>', 0)
            ->get();
    
        $valorInsumos = $estoque->sum(fn($item) => $item->preco_insumo * $item->quantidade);
        $itensNoEstoque = $estoque->sum('quantidade');
    
        // 1. Calcular o CMV
        $saldoInicial = ControleSaldoEstoque::where('unidade_id', $unidadeId)
            ->whereDate('data_ajuste', '=', $startDateConverted)
            ->orderBy('data_ajuste', 'desc')
            ->first();

        if (!$saldoInicial) {
            $saldoInicial = ControleSaldoEstoque::where('unidade_id', $unidadeId)
                ->whereDate('data_ajuste', '<', $startDateConverted)
                ->orderBy('data_ajuste', 'desc')
                ->first();

            if (!$saldoInicial) {
                $saldoInicial = ControleSaldoEstoque::where('unidade_id', $unidadeId)
                    ->orderBy('data_ajuste', 'asc') // Busca o primeiro ajuste da unidade
                    ->first();

                if (!$saldoInicial) {
                    return response()->json(['error' => 'Não há saldo inicial disponível para esta unidade.'], 400);
                }
            }
        }

        $estoqueInicialValor = $saldoInicial ? $saldoInicial->ajuste_saldo : 0;
    
        // Compras no período
        $compras = MovimentacoesEstoque::where('unidade_id', $unidadeId)
            ->where('operacao', 'Entrada')
            ->whereBetween('created_at', [$startDateConverted, $endDateConverted])
            ->get();
    
        $comprasValor = $compras->sum(fn($item) => $item->preco_insumo * $item->quantidade);
    
        // Estoque final
        $estoqueFinal = UnidadeEstoque::where('unidade_id', $unidadeId)
            ->where('quantidade', '>', 0)
            ->where('created_at', '<=', $endDateConverted)
            ->get();
    
        $estoqueFinalValor = $estoqueFinal->sum(fn($item) => $item->preco_insumo * $item->quantidade);
    
        // Calcular o CMV
        $cmv = $estoqueInicialValor + $comprasValor - $estoqueFinalValor;
    
        Log::info('Calculando CMV', [
            'estoqueInicialValor' => $estoqueInicialValor,
            'comprasValor' => $comprasValor,
            'estoqueFinalValor' => $estoqueFinalValor,
            'cmv' => $cmv,
        ]);
    
        return response()->json([
            'valorInsumos' => number_format($valorInsumos, 2, ',', '.'),
            'itensNoEstoque' => number_format($itensNoEstoque, 2, ',', '.'), // Mantém 2 casas para kg
            'historicoMovimentacoes' => $historicoMovimentacoes->values(),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'saldo_estoque_inicial' => number_format($estoqueInicialValor, 2, ',', '.'),
            'entradas_durante_periodo' => number_format($comprasValor, 2, ',', '.'),
            'saldo_estoque_final' => number_format($estoqueFinalValor, 2, ',', '.'),
            'cmv' => number_format($cmv, 2, ',', '.'),
        ]);
    }


    // Lista os fornecederes
    public function unidadeForencedores()
    {
        // Recupera todos os fornecedores
        $fornecedores = Fornecedor::all();

        // Usando o map para selecionar os dados desejados
        $fornecedoresData = $fornecedores->map(function ($fornecedor) {
            return [
                'id' => $fornecedor->id,
                'cnpj' => $fornecedor->cnpj,
                'razao_social' => $fornecedor->razao_social,
                'email' => $fornecedor->email,
                'whatsapp' => $fornecedor->whatsapp,
                'estado' => $fornecedor->estado,
            ];
        });

        return response()->json([
            'data' => $fornecedoresData
        ], 200);
    }

    // Adiciona os produtos a unidade selecionada
    public function armazenarEntrada(Request $request)
    {
        // Validação dos dados recebidos
        $validatedData = $request->validate([
            'fornecedor_id' => 'nullable|integer|exists:fornecedores,id',
            'itens' => 'required|array',
            'itens.*.id' => 'required|integer|exists:lista_produtos,id',
            'itens.*.categoria_id' => 'required|integer|exists:categorias_produtos,id',
            'itens.*.quantidade' => 'required|numeric|min:0',
            'itens.*.valorUnitario' => 'required|numeric|min:0',
            'itens.*.unidadeDeMedida' => 'nullable|string|in:a_granel,unitario',
        ], [
            'itens.required' => 'A lista de itens é obrigatória.',
            'itens.*.id.exists' => 'O insumo selecionado não foi encontrado.',
            'itens.*.categoria_id' => 'Está faltando o id da categoria!',
            'itens.*.quantidade.min' => 'A quantidade deve ser maior ou igual a 0.',
            'itens.*.valorUnitario.min' => 'O valor unitário deve ser maior ou igual a 0.',
        ]);

        DB::beginTransaction();

        try {
            foreach ($validatedData['itens'] as $item) {
                $quantidade = floatval($item['quantidade']);
                $unidadeMedida = $item['unidadeDeMedida'] === 'a_granel' ? 'kg' : 'unidade';

                // Calcular o preço por unidade corretamente
                $precoPorUnidade = ($unidadeMedida === 'kg' && $quantidade > 0) 
                    ? floatval($item['valorUnitario']) / $quantidade // Valor total dividido pela quantidade para obter preço por kg
                    : floatval($item['valorUnitario']);             // Para unitário, mantém o valor informado

                // Criar um novo registro no estoque com lote
                $loteId = UnidadeEstoque::create([
                    'insumo_id' => $item['id'],
                    'fornecedor_id' => $validatedData['fornecedor_id'] ?? null,
                    'usuario_id' => Auth::id(),
                    'unidade_id' => Auth::user()->unidade_id,
                    'quantidade' => $quantidade,
                    'preco_insumo' => $precoPorUnidade, // Salva o preço por kg ou por unidade
                    'categoria_id' => $item['categoria_id'],
                    'operacao' => 'Entrada',
                    'unidade' => $unidadeMedida,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->id;

                // Registrar a movimentação no histórico de estoques
                MovimentacoesEstoque::create([
                    'insumo_id' => $item['id'],
                    'fornecedor_id' => $validatedData['fornecedor_id'] ?? null,
                    'usuario_id' => Auth::id(),
                    'quantidade' => $quantidade,
                    'preco_insumo' => $precoPorUnidade, // Salva o preço por kg ou por unidade
                    'operacao' => 'Entrada',
                    'unidade' => $unidadeMedida,
                    'unidade_id' => Auth::user()->unidade_id,
                ]);
            }

            $unidade_id = Auth::user()->unidade_id;

            // Calcular o valor total do estoque
            $valorInsumos = UnidadeEstoque::where('unidade_id', $unidade_id)
                ->where('quantidade', '>', 0)
                ->sum(DB::raw('preco_insumo * quantidade'));

            $saldoAtual = $valorInsumos;

            DB::table('controle_saldo_estoques')->insert([
                'ajuste_saldo' => $saldoAtual,
                'data_ajuste' => now(),
                'motivo_ajuste' => 'Atualização após entrada',
                'unidade_id' => $unidade_id,
                'responsavel_id' => Auth::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json(['message' => 'Itens armazenados com sucesso!'], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao armazenar entrada: ' . $e->getMessage());
            return response()->json(['error' => 'Erro ao armazenar itens. Tente novamente mais tarde.'], 500);
        }
    }

    public function update(Request $request, $loteId)
    {
        // Validação dos dados
        $request->validate([
            'quantidade' => 'required|numeric|min:0',
        ]);
    
        DB::beginTransaction();
    
        try {
            // Busca o lote pelo ID
            $lote = UnidadeEstoque::findOrFail($loteId);
    
            // Obtém a nova quantidade informada
            $novaQuantidade = floatval($request->input('quantidade'));
    
            // Calcula a diferença (quantidade adicionada ou removida)
            $diferencaQuantidade = $novaQuantidade - $lote->quantidade;
    
            // Atualiza a quantidade e o timestamp
            $lote->quantidade = $novaQuantidade;
            $lote->updated_at = now();
            $lote->save();
    
            // Registrar a movimentação com a diferença
            MovimentacoesEstoque::create([
                'insumo_id' => $lote->insumo_id,
                'fornecedor_id' => $lote->fornecedor_id,
                'usuario_id' => Auth::id(),
                'quantidade' => $diferencaQuantidade, // Armazena a diferença da quantidade
                'preco_insumo' => $lote->preco_insumo, // Mantém o preço por unidade já salvo
                'operacao' => 'Ajuste',
                'unidade' => $lote->unidade,
                'unidade_id' => Auth::user()->unidade_id,
            ]);
    
            // Atualiza o saldo do estoque
            $unidade_id = Auth::user()->unidade_id;
    
            // Calcular o valor total do estoque
            $valorInsumos = UnidadeEstoque::where('unidade_id', $unidade_id)
                ->where('quantidade', '>', 0)
                ->sum(DB::raw('preco_insumo * quantidade'));
    
            $saldoAtual = $valorInsumos;
    
            DB::table('controle_saldo_estoques')->insert([
                'ajuste_saldo' => $saldoAtual,
                'data_ajuste' => now(),
                'motivo_ajuste' => 'Atualização após Reajuste',
                'unidade_id' => $unidade_id,
                'responsavel_id' => Auth::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    
            DB::commit();
    
            return response()->json([
                'message' => 'Quantidade atualizada com sucesso!',
                'lote' => [
                    'id' => $lote->id,
                    'quantidade' => $lote->quantidade,
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao atualizar lote: ' . $e->getMessage());
            return response()->json(['error' => 'Erro ao atualizar quantidade. Tente novamente mais tarde.'], 500);
        }
    }
}
