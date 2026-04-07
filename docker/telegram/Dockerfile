FROM node:20-alpine

WORKDIR /app

# Copy package files first for better layer caching
COPY telegram/package.json ./

# Install production dependencies
RUN npm install --omit=dev

# Copy application source
COPY telegram/ .

CMD ["node", "server.js"]
