import google.generativeai as genai
import os

# Configura la API Key
api_key = os.environ.get('GEMINI_API_KEY')
if not api_key:
    print("Error: No se encontró la variable GEMINI_API_KEY")
else:
    genai.configure(api_key=api_key)
    print("--- MODELOS DISPONIBLES ---")
    try:
        for m in genai.list_models():
            if 'generateContent' in m.supported_generation_methods:
                print(f"Nombre: {m.name}")
    except Exception as e:
        print(f"Error al conectar: {e}")

