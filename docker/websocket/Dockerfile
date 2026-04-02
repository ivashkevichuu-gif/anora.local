FROM node:20-alpine

WORKDIR /app

# Copy package files first for better layer caching
COPY websocket/package.json websocket/package-lock.json* ./

# Install production dependencies
RUN npm ci --production

# Copy application source
COPY websocket/ .

EXPOSE 8080

CMD ["node", "server.js"]
