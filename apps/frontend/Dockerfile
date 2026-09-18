# Frontend (Vue 3 + Vite) — development image (FND-004)
#
# Runs the Vite dev server. Production build/serve wiring (multi-stage build,
# static hosting behind Nginx) is addressed in later infrastructure tasks.
FROM node:22-alpine

WORKDIR /app

# Install dependencies first for better layer caching.
COPY package.json package-lock.json* ./
RUN npm ci || npm install

COPY . .

EXPOSE 5173
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0"]
