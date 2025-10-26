# Wanda - AI Chat Assistant for MediaWiki

Wanda is a MediaWiki extension that provides an AI-powered chatbot interface for your wiki. It can answer questions about your wiki content using various LLM providers and includes both a dedicated special page and a floating chat widget.

## Features

- **Multiple LLM Providers**: Support for Ollama (self-hosted), OpenAI, Anthropic Claude, Azure OpenAI, and Google Gemini
- **Elasticsearch Text Search**: Uses Elasticsearch full‑text ranking over wiki pages
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

Add these configuration variables to your `LocalSettings.php`:

```php
// Choose your LLM provider
$wgWandaLLMProvider = 'ollama'; // Options: 'ollama', 'openai', 'anthropic', 'azure', 'gemini'

// Provider-specific settings
$wgWandaLLMApiKey = 'your-api-key-here'; // Not needed for Ollama

$wgWandaLLMModel = 'gemma:2b'; // Model name to use for the LLM provider

$wgWandaLLMApiEndpoint = 'http://localhost:11434/api/'; // API endpoint URL for the LLM provider

// Elasticsearch configuration
$wgWandaLLMElasticsearchUrl = 'http://elasticsearch:9200';

// UI and interface settings
$wgWandaShowPopup = true; // Show/hide the floating chat widget on all pages

// Custom prompt settings
$wgWandaCustomPrompt = ''; // Custom prompt template to override default behavior

$wgWandaCustomPromptTitle = ''; // Wiki page title containing custom prompt template

// LLM behavior settings
$wgWandaLLMMaxTokens = 1000; // Maximum tokens in LLM responses

$wgWandaLLMTemperature = '0.7'; // Creativity/randomness setting (0.0-1.0)

$wgWandaLLMTimeout = 30; // Request timeout in seconds for LLM calls

// Indexing settings
$wgWandaAutoReindex = true; // Automatically reindex content after update.php

$wgWandaSkipESQuery = false; //Skip Elastic Search
```

## API Actions

### Wandachat

The Wanda extension provides a MediaWiki API module called `wandachat` for programmatic access to the AI chat functionality. This API allows you to send questions and receive AI-generated responses based on your wiki's content.

**Required Parameters:**

1. `action` (string) - Must be set to `wandachat`
2. `message` (string) - The user's question or query

**Optional Parameters:**

3. `format` (string) - Response format, recommended: `json`
4. `usepublicknowledge` (boolean) - Allow fallback to public knowledge when wiki context is insufficient (default: `false`)

**Optional LLM Override Parameters:**

These parameters allow you to override the default configuration settings for individual API calls:

5. `provider` (string) - Override LLM provider (`ollama`, `openai`, `anthropic`, `azure`, `gemini`)
6. `model` (string) - Override the model name for the request
7. `apikey` (string) - Override API key for the request
8. `apiendpoint` (string) - Override API endpoint URL
9. `maxtokens` (integer) - Override maximum tokens in the response
10. `temperature` (float) - Override creativity/randomness setting (0.0-1.0)
11. `timeout` (integer) - Override request timeout in seconds
12. `customprompt` (string) - Override the default prompt template
13. `customprompttitle` (string) - Override using content from a wiki page as prompt template

### Provider Examples

**Ollama (Self-hosted)**
```php
$wgWandaLLMProvider = 'ollama';
$wgWandaLLMApiEndpoint = 'http://localhost:11434/api/';
$wgWandaLLMModel = 'gemma:2b';
```

**OpenAI**
```php
$wgWandaLLMProvider = 'openai';
$wgWandaLLMApiKey = 'sk-your-openai-api-key';
$wgWandaLLMModel = 'gpt-3.5-turbo';
```

**Anthropic Claude**
```php
$wgWandaLLMProvider = 'anthropic';
$wgWandaLLMApiKey = 'sk-ant-your-anthropic-key';
$wgWandaLLMModel = 'claude-3-haiku-20240307';
```

**Google Gemini (Generative Language API)**
```php
$wgWandaLLMProvider = 'gemini';
$wgWandaLLMApiKey = 'your-gemini-api-key'; // Obtain from Google AI Studio
$wgWandaLLMModel = 'gemini-1.5-flash'; // Or gemini-1.5-pro, etc.
// Optional: override endpoint (default used if omitted)
$wgWandaLLMApiEndpoint = 'https://generativelanguage.googleapis.com/v1';
```

For detailed configuration options, see [LLM-CONFIG.md](LLM-CONFIG.md).

## Usage

### Floating Chat Widget

The floating chat widget appears on all pages (except Special:Wanda) as a blue chat button in the bottom-right corner. Click it to open the chat interface. 

Add the following configuration in LocalSettings.php to show/hide the floating chat widget.

```php
$wgWandaShowPopup = true;
```

### Special Page

Visit `Special:Wanda` on your wiki to access the full-featured chat interface.

### Indexing Content

To enable the chatbot to answer questions about your wiki content, you need to index your pages in Elasticsearch. Use the maintenance script:

```bash
php extensions/Wanda/maintenance/ReindexAllPages.php
```

### Custom Prompt Configuration

You can configure a custom prompt in two ways:

1. Use a custom prompt directly
```php
$wgWandaCustomPrompt = "Custom_Prompt_to_be_used";
```

2. Use a custom prompt from a wiki page
```php
$wgWandaCustomPromptTitle = "Title_of_the_page";
```

**Note**: If both **$wgWandaCustomPrompt** and **$wgWandaCustomPromptTitle** are set, **$wgWandaCustomPrompt** will take precedence.

#### Automatic Reindex After Updates

This extension now schedules a full reindex automatically after you run `php maintenance/update.php` by registering the maintenance script as a post-update task. On large wikis this may be time-consuming. If you prefer to disable auto reindexing, remove the `LoadExtensionSchemaUpdates` hook entry from `extension.json` or replace the full reindex with a lighter custom script.

To keep indexing continuously fresh without large batch jobs, the extension also updates the index on page saves and file uploads via hooks.

You can also control this behavior via a configuration flag. Add to `LocalSettings.php`:

```php
// Disable automatic full reindex after update.php
$wgWandaAutoReindex = false;
```

When set to `false`, the hook will skip scheduling the maintenance script; you can still run it manually:

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
  - Google Gemini (Generative Language API)

## Architecture

1. **Content Indexing**: Wiki pages (and extracted PDF text) are stored in Elasticsearch with their raw text
2. **Query Processing**: User queries are issued as full‑text multi_match searches
3. **Retrieval**: Elasticsearch returns the most relevant documents by BM25 scoring (with title boosted)
4. **Response Generation**: The LLM generates an answer constrained to the retrieved content
5. **Incremental Updates**: Page save and file upload hooks keep the index fresh

## Security Considerations

- API keys are stored securely in MediaWiki configuration
- Content is processed by your chosen LLM provider
- For sensitive wikis, consider using self-hosted Ollama
- Configure appropriate timeouts and rate limits

## Contributing

Contributions are welcome! Please feel free to submit issues and pull requests.

## License

This extension is licensed under the MIT License.