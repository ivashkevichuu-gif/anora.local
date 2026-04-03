FROM node:20-alpine

WORKDIR /app

# Copy package files first for better layer caching
COPY websocket/package.json ./

# Install production dependencies
RUN npm install --omit=dev

# Copy application source
COPY websocket/ .

EXPOSE 8080

CMD ["node", "server.js"]
