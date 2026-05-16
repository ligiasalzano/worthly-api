# Overview

**Worthly** is an authenticated API built to power a mobile application that helps users decide whether a product is worth buying.

The API receives input from a logged-in user, which can be either a product photo or a text description. Based on that input, Worthly analyzes the product using an LLM through the Laravel AI SDK and performs a web search to find relevant product information, similar alternatives, reviews, offers, and cost-benefit comparisons.

All main product analysis workflows require authentication. Each request is associated with a specific user account, allowing the system to protect user data, keep a history of previous analyses, organize user activity, and prepare the project for future features such as saved products, comparison history, usage limits, and personalized recommendations.

The first version of the project is intentionally simple. The MVP focuses only on the API layer and uses GPT-5.5 with the model’s built-in web search capability. Advanced harness engineering concepts such as tool orchestration, validation layers, memory, multi-step reasoning control, and custom search pipelines will be introduced later as the project evolves.

The main goal of Worthly is to help users make better purchase decisions by transforming a simple image or text input into a clear buying recommendation.

# Key Concepts

## Authenticated API

Worthly is designed as an authenticated API. All main product analysis workflows require a logged-in user.

Authentication is required because the API needs to associate each analysis request with a specific user account. This allows the system to keep a history of previous analyses, organize user requests, control access to protected endpoints, and prepare the project for future features such as saved products, comparison history, usage limits, and personalized recommendations.

## Product Analysis

Worthly identifies the product based on the user input and extracts useful information such as category, brand, model, main features, possible price range, and relevant purchase criteria.

## Web-Based Research

The API uses web search through the LLM to collect fresh information about the product, including availability, similar products, pricing references, public reviews, and alternative options.

## Cost-Benefit Comparison

Worthly compares the identified product with similar alternatives and highlights which options may offer better value for money.

## Buying Recommendation

The API returns a structured recommendation explaining whether the product is worth buying, whether the user should wait, search for a better offer, or consider another product.

## User History

Because all workflows are authenticated, Worthly can associate each product analysis with the user who requested it. This makes it possible to store previous analyses and expose them later through the API.

## Simple MVP Architecture

The first version does not include a custom harness layer. The LLM receives the user input, performs a simple web search, analyzes the information, and returns a structured response through the API.

# Tech Stack

## Backend

- PHP 8.5
- Laravel 13
- Laravel AI SDK
- PostgreSql

## API Documentation

- OpenAPI

## Testing

- Pest 4

## AI Integration

- GPT-5.5
- Built-in model web search

# Core Workflows

## 1. User Authentication

Before using the product analysis features, the user must be authenticated.

The mobile application sends the user credentials to the API and receives an authentication token. This token must be included in all protected requests, such as text-based product analysis, image-based product analysis, similar product discovery, review analysis, offer evaluation, and final buying recommendations.

Example request:

```json
{
  "email": "user@example.com",
  "password": "password"
}
```

Example response:

```json
{
  "token": "example-auth-token",
  "token_type": "Bearer"
}
```

After authentication, the mobile application sends the token in the request headers:

```http
Authorization: Bearer example-auth-token
```

## 2. Text-Based Product Analysis

The authenticated user sends a text input describing a product.

Example:

```json
{
  "input_type": "text",
  "query": "Is the Logitech MX Master 3S worth buying?"
}
```

The API sends the text to the LLM, performs a web search, analyzes the product, compares alternatives, and returns a structured buying recommendation linked to the authenticated user.

## 3. Image-Based Product Analysis

The authenticated user uploads or sends a photo of a product.

Example:

```json
{
  "input_type": "image",
  "image": "product-image.jpg"
}
```

The API uses the LLM to identify the product from the image, search for product information online, compare similar options, and return a purchase analysis linked to the authenticated user.

## 4. Similar Product Discovery

After identifying the product, Worthly searches for similar items that may offer better value, better reviews, lower price, or stronger features.

The response should include:

- Found product
- Similar products
- Price references
- Strengths and weaknesses
- Best cost-benefit option
- Buying recommendation

## 5. Review and Reputation Analysis

Worthly summarizes public opinions and reviews found online to help the user understand common pros, cons, complaints, and positive feedback about the product.

## 6. Offer and Price Evaluation

The API helps the user evaluate whether the current product price is attractive compared to similar products and available offers.

## 7. Analysis History

The authenticated user can access previous product analyses made through the API.

This allows the mobile application to display a history of analyzed products, previous recommendations, compared alternatives, and past buying decisions.

Example response:

```json
{
  "data": [
    {
      "id": 1,
      "product_name": "Logitech MX Master 3S",
      "input_type": "text",
      "recommendation": "buy_if_price_is_good",
      "created_at": "2026-05-14T10:30:00Z"
    },
    {
      "id": 2,
      "product_name": "Sony WH-1000XM5",
      "input_type": "image",
      "recommendation": "consider_alternatives",
      "created_at": "2026-05-14T11:45:00Z"
    }
  ]
}
```

## 8. Final Buying Decision

Worthly returns a final recommendation in a clear format.

Example response structure:

```json
{
  "product": {
    "name": "Logitech MX Master 3S",
    "category": "Wireless mouse",
    "estimated_price_range": "$80 - $110"
  },
  "summary": "The Logitech MX Master 3S is a premium wireless mouse focused on productivity, comfort, and precision.",
  "similar_products": [
    {
      "name": "Logitech MX Master 2S",
      "reason": "Older model with lower price and similar productivity features."
    },
    {
      "name": "Razer Pro Click",
      "reason": "Alternative focused on ergonomics and professional use."
    }
  ],
  "cost_benefit_analysis": "The MX Master 3S is worth it if the user values ergonomics, silent clicks, and productivity features. Users looking for a cheaper option may prefer the MX Master 2S.",
  "recommendation": {
    "decision": "buy_if_price_is_good",
    "reason": "It is a strong product, but the best decision depends on the current price compared to alternatives."
  }
}
```