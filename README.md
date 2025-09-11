# Wanda - AI Chat Assistant for MediaWiki

Wanda is a MediaWiki extension that provides an AI-powered chatbot interface for your wiki. It can answer questions about your wiki content using various LLM providers and includes both a dedicated special page and a floating chat widget.

## Features

- **Multiple LLM Providers**: Support for Ollama (self-hosted), OpenAI, Anthropic Claude, and Azure OpenAI
- **Vector Search**: Uses Elasticsearch with embeddings for semantic search
- **Text Search Fallback**: Falls back to text-based search when embeddings are unavailable
- **Floating Chat Widget**: Always-accessible chat button on all pages
- **Special Page**: Dedicated chat interface at Special:Wanda
- **Responsive Design**: Works on desktop and mobile devices
- **Secure Configuration**: API key management and timeout controls

## Installation

1. Clone or download this extension to your MediaWiki `extensions/` directory
2. Add the following to your `LocalSettings.php`:

```php
wfLoadExtension( 'Wanda' );
```

3. Configure your preferred LLM provider (see Configuration section)
4. Run MediaWiki update script:

```bash
php maintenance/update.php
```

## Configuration

### Basic Setup

Add these configuration variables to your `LocalSettings.php`:

```php
// Choose your LLM provider
$wgLLMProvider = 'ollama'; // Options: 'ollama', 'openai', 'anthropic', 'azure'

// Provider-specific settings
$wgLLMApiKey = 'your-api-key-here'; // Not needed for Ollama
$wgLLMModel = 'gemma:2b';
$wgLLMApiEndpoint = 'http://localhost:11434/api/';

// Elasticsearch configuration
$wgLLMElasticsearchUrl = 'http://elasticsearch:9200';
```

### Provider Examples

**Ollama (Self-hosted)**
```php
$wgLLMProvider = 'ollama';
$wgLLMApiEndpoint = 'http://localhost:11434/api/';
$wgLLMModel = 'gemma:2b';
$wgLLMEmbeddingModel = 'nomic-embed-text';
```

**OpenAI**
```php
$wgLLMProvider = 'openai';
$wgLLMApiKey = 'sk-your-openai-api-key';
$wgLLMModel = 'gpt-3.5-turbo';
$wgLLMEmbeddingModel = 'text-embedding-ada-002';
```

**Anthropic Claude**
```php
$wgLLMProvider = 'anthropic';
$wgLLMApiKey = 'sk-ant-your-anthropic-key';
$wgLLMModel = 'claude-3-haiku-20240307';
```

For detailed configuration options, see [LLM-CONFIG.md](LLM-CONFIG.md).

## Usage

### Floating Chat Widget

The floating chat widget appears on all pages (except Special:Wanda) as a blue chat button in the bottom-right corner. Click it to open the chat interface.

### Special Page

Visit `Special:Wanda` on your wiki to access the full-featured chat interface.

### Indexing Content

To enable the chatbot to answer questions about your wiki content, you need to index your pages in Elasticsearch with embeddings. Use the maintenance script:

```bash
php extensions/Wanda/maintenance/ReindexAllPages.php
```

## Requirements

- MediaWiki 1.36.0 or later
- PHP 7.4 or later
- Elasticsearch (for content indexing and search)
- One of the supported LLM providers:
  - Ollama (self-hosted)
  - OpenAI API access
  - Anthropic Claude API access
  - Azure OpenAI service

## Architecture

1. **Content Indexing**: Wiki pages are processed and stored in Elasticsearch with embeddings
2. **Query Processing**: User queries are converted to embeddings (when available)
3. **Similarity Search**: Elasticsearch finds the most relevant content
4. **Response Generation**: The LLM generates responses based on the retrieved content
5. **Fallback Mechanism**: Text-based search when embeddings are unavailable

## Security Considerations

- API keys are stored securely in MediaWiki configuration
- Content is processed by your chosen LLM provider
- For sensitive wikis, consider using self-hosted Ollama
- Configure appropriate timeouts and rate limits

## Contributing

Contributions are welcome! Please feel free to submit issues and pull requests.

## License

This extension is licensed under the MIT License.
