Cara menjalankan Backend

Sebelum menyalakan cek dulu Ipv4 menggunakan command "ipconfig" pada CMD

Menyalakan Laravel
1. CD folder laravel
2. Ketik "php artisan serve --host=?.?.?.? --port=8000"
*Tanda tanya itu isi dengan ipv4 setelah cek ipv4 pada command.
*Perlu di note ipv4 sering berganti
Contoh : php artisan serve --host=192.168.1.39 --port=8000

Menyalakan FastAPI
1. CD folder fastAPI
2. ketik "venv\Scripts\activate"
3. uvicorn main:app --host 0.0.0.0 --port 8001 --reload

DB::table('absensis')->get();

cd /d "C:\Users\Steven\Documents\cloudflared"
cloudflared.exe tunnel --url http://localhost:8001
