<img src="https://github.com/user-attachments/assets/3f76a96a-ac3e-46ff-9665-ac1eae92ab59" width="150" height="150">

# AI Playground

A lightweight, self-hostable AI chat interface designed for quick experimentation with various AI APIs. No logins, no persistence—just a clean, temporary sandbox where you can test prompts, tweak AI settings, and interact with models in real time.

**Live Demo:** [playground.lovechan.cc](https://playground.lovechan.cc/)  
**GitHub Repository:** [github.com/lovechan404/ai-playground/](https://github.com/lovechan404/ai-playground/)


## Table of Contents
- [Features](#features)
- [Getting Started](#getting-started)
  - [Prerequisites](#prerequisites)
  - [Installation](#installation)
  - [API Configuration](#api-configuration)
- [Disclaimer](#disclaimer)
- [How it Works](#how-it-works)
- [License](#license)


## Features

* Connects to many AI chat APIs.
* Configure API key, model, prompt, and more.
* No database needed; uses PHP sessions.
* Renders AI responses using Markdown.
* Includes a "Copy" button for code blocks.
* Edit, regenerate, and delete messages.
* Supports multiple AI response versions.
* Stop long-running API requests. _(API responses may still be fully delivered upon refresh depending on factors such as processing speed at the time of cancellation.)_
* Import and export chat sessions (JSON).
* Basic CSRF and session cookie security.
* View raw or edit AI responses.

## Getting Started

### Prerequisites

* A web server with PHP support (e.g., Apache, Nginx with PHP-FPM).
* cURL extension for PHP enabled (for making API calls).

### Installation

1.  **Clone the repository:**
    ```bash
    git clone https://github.com/lovechan404/ai-playground.git
    ```
2.  Navigate to the project directory:
    ```bash
    cd ai-playground
    ```
3.  Ensure your web server is configured to serve PHP files from this directory.
4.  Open the `index.php` file in your browser.

### API Configuration

This playground is designed to be flexible with various OpenAI-compatible chat completion APIs.

1.  **Access API Settings:**
    * Open the AI Playground in your browser.
    * Click on the "Settings" button in the top right corner of the chat interface.
    * Select "API Settings" from the dropdown.

2.  **Configure the following fields:**

    * **Model:** Enter the specific model name for your chosen API provider.
        * *Example (OpenAI):* `gpt-4o`, `gpt-3.5-turbo`
        * *Example (Anthropic Claude):* `claude-3-opus-20240229` (Note: See API specific notes below)
        * *Example (DeepSeek):* `deepseek-chat`, `deepseek-coder`
        * *Example (Groq):* `llama3-8b-8192`, `mixtral-8x7b-32768`
        * *Example (Together.ai):* `meta-llama/Llama-3-8b-chat-hf`
        * ...and many others!

    * **API/Proxy URL (Chat Completions):** This is the full HTTP endpoint for the chat completions API.
        * *OpenAI:* `https://api.openai.com/v1/chat/completions`
        * *Anthropic Claude:* `https://api.anthropic.com/v1/messages`
            * **Note for Claude:** The Claude API has a slightly different request/response structure. This playground primarily expects an OpenAI-compatible format. For full Claude compatibility, you might need a proxy or use a Claude provider with an OpenAI-compatible endpoint.
        * *DeepSeek:* `https://api.deepseek.com/chat/completions`
        * *Groq:* `https://api.groq.com/openai/v1/chat/completions`
        * *Together.ai:* `https://api.together.xyz/v1/chat/completions`
        * *Other Proxies/Self-Hosted LLMs:* Enter your specific endpoint URL.

    * **API Key:** Your secret API key for the chosen service.
        * **Important:** Your API key is stored in the PHP session on the server. While not directly exposed to the client, always be mindful of security when handling API keys.

    * **Custom System Prompt (Optional):** Define the AI's behavior or persona.
        * *Example:* "You are a helpful assistant that always responds in pirate speak."

    * **Max Tokens (Optional):** Controls the response length.
        * **Important:** Always check the maximum token allowance of your chosen API.

    * **Temperature:** Controls response randomness (0.0 for deterministic, up to 2.0 for highly creative).

3.  Click "Save Settings". The settings are stored in your current session.

## Disclaimer

This project, AI Playground, interacts with third-party APIs, which are entirely managed and controlled by their respective providers. The usage, functionality, and availability of these APIs are subject to their own terms and conditions. The developer of this project (love-chan) does not control, endorse, or take responsibility for any issues, limitations, or consequences arising from API usage.

Users are solely responsible for configuring and using API keys properly. Make sure to review the terms of any API provider you integrate with!

## How it Works

* **Frontend:** HTML, CSS, and JavaScript for the user interface, interactivity, and AJAX communication. It handles rendering messages, creating action buttons, and managing modals.
* **Backend (PHP):**
    * Manages the conversation history and settings in the PHP session (`$_SESSION`).
    * Handles AJAX requests for sending messages, editing, deleting, regenerating, managing versions, and saving settings.
    * Includes CSRF protection.
    * Makes API calls to the configured AI provider using cURL, handling potential timeouts and cancellations.
    * Processes Markdown responses into HTML for display.


## License

This project is licensed under the **AGPL-3.0**. See the [LICENSE](LICENSE) file for details.

---

Made with ❤️ by love-chan.
