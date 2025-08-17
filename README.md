# 🏆 Rinha Backend 2025

## 🎯 Sobre o Desafio

Este projeto participa da **[Rinha de Backend 2025](https://github.com/zanfranceschi/rinha-de-backend-2025)**, um desafio que testa a capacidade de construir sistemas altamente escaláveis e resilientes. O objetivo é desenvolver um backend que intermedie solicitações de pagamentos para serviços de processamento, maximizando o lucro através da escolha inteligente entre dois processadores de pagamento com taxas diferentes.

### 🎲 Regras do Jogo

- **Objetivo**: Maximizar lucro processando pagamentos com a menor taxa possível
- **Processadores**: Dois serviços com taxas diferentes - default menor (5%), fallback maior (10%)
- **Instabilidade**: Ambos processadores podem ficar instáveis ou indisponíveis
- **Auditoria**: Endpoint de resumo para verificação de consistência pelo "Banco Central"
- **Performance**: Bônus baseado no p99 de tempo de resposta (até 20% para p99 ≤ 1ms)
- **Penalidades**: Multa de 35% por inconsistências detectadas

## 🛠️ Tecnologias

### Backend

- **Web API**: **PHP** com **ReactPHP**
- **Payments Worker**: **PHP** + **ReactPHP**
- **Storage/Queue/Cache**: **Redis**

### Infraestrutura

- **Containerização**: **Docker**
- **Orquestração**: **Docker-compose**
- **Load Balancer**: **Nginx**

## 📊 Recursos (Limite: 1.5 CPU + 350MB)

| Serviço         | CPU | Memória | Função              |
| --------------- | --- | ------- | ------------------- |
| nginx           | 0.2 | 20MB    | Load Balancer       |
| api_1           | 0.3 | 70MB    | REST API            |
| api_2           | 0.3 | 70MB    | REST API            |
| worker_payments | 0.4 | 90MB    | Processamento       |
| redis           | 0.3 | 100MB   | Storage/Cache/Queue |

**Total**: 1.5 CPU cores / 350MB RAM ✅

### Escalabilidade

- **Redis como Fila**: Processamento assíncrono
- **Sistemas distribuídos**: Múltiplas instâncias com responsabilidades definidas
- **Resource Limits**: Controle preciso de recursos

## 🚀 Como Buildar a Aplicação

### Pré-requisitos

- Docker e Docker Compose
- Git
- PHP [optional para desenvolvimento local]
- Composer [optional para desenvolvimento local]

### Para testar

```bash
# Clone o repositório
git clone <seu-repo>
cd payment-proxy-solution
```

### Produção

```bash
# Iniciar ambiente de produção
docker-compose up --build
```

### Desenvolvimento

```bash
# Instalar dependencias
cd solution
composer install
cd ..

# Iniciar ambiente de desenvolvimento
docker-compose -f docker-compose.dev.yml up --build -d
```

## 📋 Endpoints

- `POST /payments` - Recebe solicitações de pagamento
- `GET /payments-summary` - Resumo para auditoria

---
