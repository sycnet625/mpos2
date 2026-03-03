#!/var/www/marinero/venv/bin/python3
import os
import sys
import google.generativeai as genai

# 1. Configuración
api_key = os.environ.get('GEMINI_API_KEY')
if not api_key:
    print("❌ Error: No se encontró la variable GEMINI_API_KEY")
    sys.exit(1)

genai.configure(api_key=api_key)

# Usamos el modelo más capaz (Gemini 1.5 Pro o Flash)
model = genai.GenerativeModel('gemini-flash-latest')

# 2. El "Cerebro" de PALWEB (Prompt del Sistema)
# Aquí defines qué es un archivo .pago para que la IA no se pierda
SYSTEM_INSTRUCTION = """
Eres un experto desarrollador del sistema 'PALWEB POS'.
Tu tarea es ayudar a escribir, corregir y optimizar archivos de configuración con extensión '.pago'.
Reglas de PALWEB:
1. La sintaxis es estricta.
2. Si el usuario pide código, entrega SOLO el código listo para copiar.
3. Recuerda que PALWEB se integra con hardware (impresoras, cajones de dinero).
"""

def main():
    if len(sys.argv) < 2:
        print("Uso: python3 palweb_ai.py [instruccion] [archivo_opcional]")
        print("Uso: python3 palweb_ai.py list_models")
        return

    command = sys.argv[1]

    if command == "list_models":
        list_available_models()
        return

    user_query = command
    
    # Si hay un archivo adjunto, lo leemos
    file_content = ""
    if len(sys.argv) > 2:
        file_path = sys.argv[2]
        try:
            with open(file_path, 'r') as f:
                file_content = f.read()
            user_query += f"\n\n--- CONTENIDO DEL ARCHIVO ({file_path}) ---\n{file_content}"
        except Exception as e:
            print(f"Error leyendo archivo: {e}")
            return

def list_available_models():
    print("✨ Modelos disponibles:")
    for model in genai.list_models():
        print(f"- {model.name}")

    # 3. Enviamos a Gemini
    print("🤖 Analizando con Gemini...")
    full_prompt = f"{SYSTEM_INSTRUCTION}\n\nPREGUNTA USUARIO:\n{user_query}"
    
    response = model.generate_content(full_prompt)
    
    print("\n" + "="*40)
    print(response.text)
    print("="*40 + "\n")

if __name__ == "__main__":
    main()

