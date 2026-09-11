<?php
declare(strict_types=1);
/*
 * LS Meet - configuração de transcrição no servidor.
 * Este arquivo NÃO contém segredos fixos. Configure pelas variáveis de ambiente
 * do servidor ou edite uma cópia protegida fora da pasta pública.
 */
return [
    // auto | openai_compatible | local_command
    'provider' => getenv('NAA_TRANSCRIBE_PROVIDER') ?: 'auto',

    // Provedor HTTP compatível com multipart /audio/transcriptions.
    'api_url' => getenv('NAA_TRANSCRIBE_API_URL') ?: 'https://api.openai.com/v1/audio/transcriptions',
    'api_key' => getenv('NAA_TRANSCRIBE_API_KEY') ?: '',
    'model' => getenv('NAA_TRANSCRIBE_MODEL') ?: 'whisper-1',
    'language' => getenv('NAA_TRANSCRIBE_LANGUAGE') ?: 'pt',

    // Opcional: motor local. Exemplo de comando:
    // /usr/local/bin/whisper-cli -m /opt/models/ggml-base.bin -f %INPUT% -l pt -otxt -of %OUTPUT_BASE%
    // O comando pode usar: %INPUT%, %OUTPUT%, %OUTPUT_BASE%, %LANG%.
    'local_command' => getenv('NAA_WHISPER_COMMAND') ?: '',

    'max_bytes' => 5 * 1024 * 1024,
    'temporary_ttl_seconds' => 3600,
];
