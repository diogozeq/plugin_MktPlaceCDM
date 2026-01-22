import os
from pathlib import Path

def collect_all_codes(root_dir='.', output_file='AllCodes.txt'):
    """Coleta todo o código de todos os arquivos do projeto"""
    
    # Extensões de código para incluir
    code_extensions = {
        '.php', '.js', '.css', '.py', '.json', '.md', '.xml',
        '.html', '.vue', '.ts', '.jsx', '.tsx', '.sql'
    }
    
    # Pastas para ignorar
    ignore_dirs = {
        'node_modules', '.git', '__pycache__', 'vendor', 
        '.vscode', '.idea', 'dist', 'build'
    }
    
    files_content = []
    
    # Percorre todos os arquivos
    for root, dirs, files in os.walk(root_dir):
        # Remove pastas ignoradas
        dirs[:] = [d for d in dirs if d not in ignore_dirs]
        
        for file in files:
            file_path = Path(root) / file
            
            # Verifica se é um arquivo de código
            if file_path.suffix in code_extensions:
                try:
                    with open(file_path, 'r', encoding='utf-8') as f:
                        content = f.read()
                    
                    relative_path = file_path.relative_to(root_dir)
                    files_content.append({
                        'path': str(relative_path),
                        'content': content,
                        'extension': file_path.suffix
                    })
                except Exception as e:
                    print(f"Erro ao ler {file_path}: {e}")
    
    # Ordena por caminho
    files_content.sort(key=lambda x: x['path'])
    
    # Escreve no arquivo de saída
    with open(output_file, 'w', encoding='utf-8') as out:
        out.write("=" * 80 + "\n")
        out.write(f"TODOS OS CÓDIGOS DO PROJETO\n")
        out.write(f"Total de arquivos: {len(files_content)}\n")
        out.write("=" * 80 + "\n\n")
        
        for item in files_content:
            out.write("\n" + "=" * 80 + "\n")
            out.write(f"ARQUIVO: {item['path']}\n")
            out.write(f"TIPO: {item['extension']}\n")
            out.write("=" * 80 + "\n\n")
            out.write(item['content'])
            out.write("\n\n")
    
    print(f"✓ Concluído! {len(files_content)} arquivos coletados em '{output_file}'")
    
    # Estatísticas
    stats = {}
    for item in files_content:
        ext = item['extension']
        stats[ext] = stats.get(ext, 0) + 1
    
    print("\nEstatísticas por tipo:")
    for ext, count in sorted(stats.items()):
        print(f"  {ext}: {count} arquivo(s)")

if __name__ == '__main__':
    collect_all_codes()
